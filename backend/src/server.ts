import http from 'node:http';
import { createHmac, randomUUID, timingSafeEqual } from 'node:crypto';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { buildCouriers } from './couriers';
import type { Address, ParcelInput, PaymentMode } from './couriers/types';
import { CourierError } from './couriers/http';
import { Store, type ShipmentRecord } from './store';

// Dependency-free API server. Creates shipments through courier adapters and serves the site.
// Courier tokens stay in the environment and are never sent to the browser.

const env = process.env;
const PORT = Number(env.PORT ?? 3000);
const ADMIN_PASSWORD = env.ADMIN_PASSWORD ?? '';
const SESSION_SECRET = env.SESSION_SECRET ?? '';
if (ADMIN_PASSWORD.length < 8 || SESSION_SECRET.length < 32) {
  console.error('Refusing to start: set ADMIN_PASSWORD (8+ chars) and SESSION_SECRET (32+ chars) in .env');
  process.exit(1);
}
const SITE_DIR = path.resolve(env.SITE_DIR ?? path.join(process.cwd(), '..', 'site'));
const couriers = buildCouriers(env);
const store = new Store(env.DATA_DIR ?? './data');

// ---------- sessions (HMAC-signed, 12h) ----------
const b64 = (b: Buffer | string) => Buffer.from(b).toString('base64url');
const sign = (p: string) => createHmac('sha256', SESSION_SECRET).update(p).digest('base64url');
const eq = (a: string, b: string) => { const x = Buffer.from(a), y = Buffer.from(b); return x.length === y.length && timingSafeEqual(x, y); };
function issueToken() { const p = b64(JSON.stringify({ exp: Date.now() + 12 * 3600_000 })); return `${p}.${sign(p)}`; }
function validToken(t: string | undefined): boolean {
  if (!t) return false;
  const [p, s] = t.split('.');
  if (!p || !s || !eq(s, sign(p))) return false;
  try { return JSON.parse(Buffer.from(p, 'base64url').toString()).exp > Date.now(); } catch { return false; }
}

// ---------- tiny helpers ----------
class HttpError extends Error { constructor(public status: number, message: string) { super(message); } }
const attempts = new Map<string, number[]>();
function throttleLogin(ip: string) {
  const now = Date.now(), recent = (attempts.get(ip) ?? []).filter(t => now - t < 60_000);
  if (recent.length >= 5) throw new HttpError(429, 'Too many login attempts. Wait a minute and try again.');
  recent.push(now); attempts.set(ip, recent);
}
async function readJson(req: http.IncomingMessage): Promise<any> {
  const chunks: Buffer[] = []; let size = 0;
  for await (const c of req) { size += c.length; if (size > 100_000) throw new HttpError(413, 'Request too large'); chunks.push(c as Buffer); }
  if (!chunks.length) return {};
  try { return JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { throw new HttpError(400, 'Body must be valid JSON'); }
}
const send = (res: http.ServerResponse, status: number, body: unknown) => {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' });
  res.end(JSON.stringify(body));
};
const str = (v: unknown, name: string, max = 200) => {
  if (typeof v !== 'string' || !v.trim() || v.length > max) throw new HttpError(400, `${name} is required`);
  return v.trim();
};
const pin = (v: unknown, name: string) => { const s = String(v ?? '').trim(); if (!/^\d{6}$/.test(s)) throw new HttpError(400, `${name} must be a 6-digit pincode`); return s; };
const num = (v: unknown, name: string, min = 0) => { const n = Number(v); if (!Number.isFinite(n) || n <= min) throw new HttpError(400, `${name} must be a number above ${min}`); return n; };
const mode = (v: unknown): PaymentMode => { if (v !== 'Prepaid' && v !== 'COD') throw new HttpError(400, 'paymentMode must be Prepaid or COD'); return v; };
function courier(code: unknown) {
  const c = couriers.get(String(code ?? 'delhivery'));
  if (!c) throw new HttpError(400, `Courier "${code}" is not configured on this server`);
  return c;
}

// ---------- routes ----------
type Handler = (req: http.IncomingMessage, url: URL, params: string[]) => Promise<unknown>;
const routes: { method: string; re: RegExp; auth: boolean; fn: Handler }[] = [];
const route = (method: string, re: RegExp, auth: boolean, fn: Handler) => routes.push({ method, re, auth, fn });

// What still needs fixing in .env before real bookings can work (setting names only, never values).
function setupIssues(): string[] {
  const out: string[] = [];
  if (!couriers.has('delhivery')) out.push('DELHIVERY_API_TOKEN is not set');
  else if (env.DELHIVERY_DRY_RUN === 'false') {
    if (!env.DELHIVERY_PICKUP_LOCATION) out.push('DELHIVERY_PICKUP_LOCATION is not set (your warehouse name exactly as in Delhivery)');
    if (!env.DELHIVERY_SELLER_GST_TIN) out.push('DELHIVERY_SELLER_GST_TIN is not set');
    if (!env.DELHIVERY_HSN_CODE) out.push('DELHIVERY_HSN_CODE is not set');
  }
  return out;
}
route('GET', /^\/api\/health$/, false, async () => ({
  ok: true, couriers: [...couriers.keys()], setup: setupIssues(),
  dryRun: Object.fromEntries([...couriers].map(([k, c]) => [k, (c as any).isDryRun ?? true])),
}));

route('POST', /^\/api\/login$/, false, async (req) => {
  throttleLogin(req.socket.remoteAddress ?? 'x');
  const { password } = await readJson(req);
  if (typeof password !== 'string' || !eq(password, ADMIN_PASSWORD)) throw new HttpError(401, 'Wrong password');
  return { token: issueToken() };
});

route('GET', /^\/api\/couriers$/, true, async () => [...couriers.values()].map(c => ({
  code: c.code, status: 'integration_pending', dryRun: (c as any).isDryRun ?? true,
  note: 'Adapter built. Not yet verified against the live courier API.',
})));

route('GET', /^\/api\/serviceability$/, true, async (_r, url) => {
  const p = pin(url.searchParams.get('pincode'), 'pincode');
  const out: Record<string, unknown> = {};
  for (const [code, c] of couriers) { try { const { raw, ...r } = await c.serviceability(p); out[code] = r; } catch (e) { out[code] = { error: (e as Error).message }; } }
  return out;
});

route('POST', /^\/api\/rates$/, true, async (req) => {
  const b = await readJson(req);
  const from = pin(b.fromPin, 'fromPin'), to = pin(b.toPin, 'toPin'), w = num(b.weightGrams, 'weightGrams'), m = mode(b.paymentMode);
  const cod = m === 'COD' ? num(b.codAmount, 'codAmount') : 0;
  const rates: unknown[] = [];
  for (const [code, c] of couriers) {
    try { const { raw, ...r } = await c.calculateRate(from, to, w, m, cod); rates.push(r); }
    catch (e) { rates.push({ courier: code, error: (e as Error).message }); }
  }
  return { label: 'Courier cost. Add your margin before showing sellers.', rates };
});

route('POST', /^\/api\/shipments$/, true, async (req) => {
  const b = await readJson(req);
  const c = courier(b.courier);
  const t = b.to ?? {}, p = b.parcel ?? {};
  const orderId = str(b.orderId, 'orderId', 60);
  const phone = String(t.phone ?? '').replace(/\D/g, '').slice(-10);
  if (!/^[6-9]\d{9}$/.test(phone)) throw new HttpError(400, 'to.phone must be a valid 10-digit mobile number');
  const to: Address = { name: str(t.name, 'to.name', 100), phone, line1: str(t.line1, 'to.line1', 300), city: str(t.city, 'to.city', 80), state: str(t.state, 'to.state', 80), pincode: pin(t.pincode, 'to.pincode') };
  const payment = mode(p.paymentMode);
  const declaredValue = num(p.declaredValue, 'parcel.declaredValue');
  const parcel: ParcelInput = {
    orderId, paymentMode: payment, declaredValue, codAmount: payment === 'COD' ? num(p.codAmount ?? declaredValue, 'parcel.codAmount') : undefined,
    weightGrams: num(p.weightGrams, 'parcel.weightGrams'), lengthCm: num(p.lengthCm ?? 10, 'lengthCm'), breadthCm: num(p.breadthCm ?? 10, 'breadthCm'), heightCm: num(p.heightCm ?? 10, 'heightCm'),
    productDescription: str(p.productDescription, 'parcel.productDescription', 200), quantity: Math.max(1, Math.round(num(p.quantity ?? 1, 'quantity'))),
  };
  const dryRun = (c as any).isDryRun ?? true;

  // One live shipment per order: a retry returns the existing one instead of booking twice.
  return store.tx(async rows => {
    const existing = rows.find(r => r.orderId === orderId && r.courier === c.code && r.dryRun === dryRun && r.status !== 'cancelled');
    if (existing) return { shipment: existing, reused: true };
    const result = await c.createShipment(to, parcel, { courierId: b.courierId });
    const rec: ShipmentRecord = {
      id: randomUUID(), orderId, courier: c.code, awb: result.awb, courierRef: result.courierRef, status: 'created', dryRun,
      createdAt: new Date().toISOString(),
      to: { name: to.name, phone: to.phone, line1: to.line1, city: to.city, state: to.state, pincode: to.pincode },
      parcel: { paymentMode: payment, declaredValue, codAmount: parcel.codAmount, weightGrams: parcel.weightGrams, productDescription: parcel.productDescription, quantity: parcel.quantity },
    };
    rows.push(rec);
    return { shipment: rec, reused: false, notice: dryRun ? 'DRY RUN: nothing was booked at the courier. Set DELHIVERY_DRY_RUN=false to book real shipments.' : undefined };
  });
});

route('GET', /^\/api\/shipments$/, true, async () => store.list());

route('GET', /^\/api\/shipments\/([\w-]+)$/, true, async (_r, _u, [id]) => {
  const s = await store.get(id); if (!s) throw new HttpError(404, 'Shipment not found'); return s;
});

route('POST', /^\/api\/shipments\/([\w-]+)\/cancel$/, true, async (_r, _u, [id]) => store.tx(async rows => {
  const s = rows.find(r => r.id === id); if (!s) throw new HttpError(404, 'Shipment not found');
  if (s.status === 'cancelled') return s;
  await courier(s.courier).cancelShipment(s.awb, s.courierRef);
  s.status = 'cancelled'; s.cancelledAt = new Date().toISOString();
  return s;
}));

route('GET', /^\/api\/shipments\/([\w-]+)\/label$/, true, async (_r, _u, [id]) => {
  const s = await store.get(id); if (!s) throw new HttpError(404, 'Shipment not found');
  if (s.dryRun) throw new HttpError(409, 'This is a dry-run shipment, so there is no real label.');
  const { raw, ...l } = await courier(s.courier).generateLabel(s.awb, 'A4', s.courierRef); return l;
});

route('GET', /^\/api\/tracking\/([\w-]+)$/, true, async (_r, url, [awb]) => {
  const c = courier(url.searchParams.get('courier'));
  if (awb.startsWith('DRYRUN-')) throw new HttpError(409, 'Dry-run shipments have no tracking.');
  const { raw, ...t } = await c.trackShipment(awb); return t;
});

// ---------- static site ----------
const MIME: Record<string, string> = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon', '.json': 'application/json' };
async function serveStatic(url: URL, res: http.ServerResponse) {
  let rel = decodeURIComponent(url.pathname);
  if (rel.endsWith('/')) rel += 'index.html';
  const file = path.resolve(SITE_DIR, '.' + rel);
  if (file !== SITE_DIR && !file.startsWith(SITE_DIR + path.sep)) return send(res, 403, { error: 'Forbidden' });
  try {
    const data = await fs.readFile(file);
    res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] ?? 'application/octet-stream', 'X-Content-Type-Options': 'nosniff' });
    res.end(data);
  } catch { send(res, 404, { error: 'Not found' }); }
}

// ---------- server ----------
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url ?? '/', 'http://x');
  try {
    if (!url.pathname.startsWith('/api/')) return await serveStatic(url, res);
    const r = routes.find(x => x.method === req.method && x.re.test(url.pathname));
    if (!r) return send(res, 404, { error: 'Unknown endpoint' });
    if (r.auth) {
      const h = req.headers.authorization ?? '';
      if (!validToken(h.startsWith('Bearer ') ? h.slice(7) : undefined)) return send(res, 401, { error: 'Log in first' });
    }
    const m = url.pathname.match(r.re)!;
    send(res, 200, await r.fn(req, url, m.slice(1)));
  } catch (e) {
    if (e instanceof HttpError) return send(res, e.status, { error: e.message });
    if (e instanceof CourierError) return send(res, 502, { error: `Courier error: ${e.message}`, retryable: e.retryable });
    console.error('Unhandled', (e as Error).message);
    send(res, 500, { error: 'Something went wrong on the server' });
  }
});
server.listen(PORT, () => {
  console.log(`FastShip server on http://localhost:${PORT}  (panel: http://localhost:${PORT}/app/)`);
  console.log(`Couriers: ${[...couriers.keys()].join(', ') || 'none configured'}`);
  const d = (couriers.get('delhivery') as any)?.isDryRun;
  if (d !== undefined) console.log(d ? 'Delhivery is in DRY RUN mode: nothing is booked. Set DELHIVERY_DRY_RUN=false for real bookings.' : 'Delhivery is LIVE: shipments will be booked for real.');
  for (const i of setupIssues()) console.log('FIX NEEDED: ' + i);
});
