import http from 'node:http';
import { randomUUID, timingSafeEqual } from 'node:crypto';
import { promises as fs, existsSync } from 'node:fs';
import path from 'node:path';
import { buildCouriers } from './couriers';
import type { Address, ParcelInput, PaymentMode } from './couriers/types';
import { CourierError } from './couriers/http';
import { Store, type ShipmentRecord } from './store';
import { Db, blankState, type SellerState, type User } from './db';
import { checkPassword, hashPassword, makeTokens } from './auth';

// Dependency-free API server. Creates shipments through courier adapters and serves the site.
// Courier tokens stay in the environment and are never sent to the browser.

const env = process.env;
const PORT = Number(env.PORT ?? 3000);
const ADMIN_EMAIL = (env.ADMIN_EMAIL ?? '').trim().toLowerCase();
const ADMIN_PASSWORD = env.ADMIN_PASSWORD ?? '';
const SESSION_SECRET = env.SESSION_SECRET ?? '';
if (!/^\S+@\S+\.\S+$/.test(ADMIN_EMAIL) || ADMIN_PASSWORD.length < 8 || SESSION_SECRET.length < 32) {
  console.error('Refusing to start: set ADMIN_EMAIL, ADMIN_PASSWORD (8+ chars) and SESSION_SECRET (32+ chars) in .env');
  process.exit(1);
}
// The website folder: SITE_DIR if set, else ./site (ready-made pack), else ../site (source repo).
const SITE_DIR = path.resolve(env.SITE_DIR ?? (existsSync(path.join(process.cwd(), 'site')) ? path.join(process.cwd(), 'site') : path.join(process.cwd(), '..', 'site')));
const couriers = buildCouriers(env);
const store = new Store(env.DATA_DIR ?? './data');
const db = new Db(env.DATA_DIR ?? './data');
const tokens = makeTokens(SESSION_SECRET);

const eq = (a: string, b: string) => { const x = Buffer.from(a), y = Buffer.from(b); return x.length === y.length && timingSafeEqual(x, y); };

// ---------- tiny helpers ----------
class HttpError extends Error { constructor(public status: number, message: string) { super(message); } }
const attempts = new Map<string, number[]>();
function throttleLogin(key: string, max = 8) {
  const ip = key, now = Date.now(), recent = (attempts.get(ip) ?? []).filter(t => now - t < 60_000);
  if (recent.length >= max) throw new HttpError(429, 'Too many login attempts. Wait a minute and try again.');
  recent.push(now); attempts.set(ip, recent);
}
async function readJson(req: http.IncomingMessage, max = 100_000): Promise<any> {
  const chunks: Buffer[] = []; let size = 0;
  for await (const c of req) { size += c.length; if (size > max) throw new HttpError(413, 'Request too large'); chunks.push(c as Buffer); }
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
type Who = { uid: string; role: 'seller' | 'admin' };
type Handler = (req: http.IncomingMessage, url: URL, params: string[], who: Who) => Promise<unknown>;
type Need = false | 'any' | 'seller' | 'admin';
const routes: { method: string; re: RegExp; auth: Need; fn: Handler }[] = [];
// auth: false = public, 'any' = any logged-in user, 'seller' / 'admin' = that role only.
// Old calls that passed true now mean any logged-in user.
const route = (method: string, re: RegExp, auth: Need | true, fn: Handler) => routes.push({ method, re, auth: auth === true ? 'any' : auth, fn });

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
  ok: true, accounts: true, couriers: [...couriers.keys()], setup: setupIssues(),
  dryRun: Object.fromEntries([...couriers].map(([k, c]) => [k, (c as any).isDryRun ?? true])),
}));

// ---------- accounts ----------
const clean = (u: User) => ({ id: u.id, email: u.email, role: u.role, name: u.name, city: u.city, mobile: u.mobile });
const balanceOf = (st: SellerState) => st.ledger.reduce((s, e) => s + (Number(e[3]) || 0) - (Number(e[2]) || 0), 0);
const emailOf = (v: unknown) => { const e = String(v ?? '').trim().toLowerCase(); if (!/^\S+@\S+\.\S+$/.test(e) || e.length > 120) throw new HttpError(400, 'Enter a valid email address'); return e; };
const passOf = (v: unknown) => { if (typeof v !== 'string' || v.length < 8 || v.length > 200) throw new HttpError(400, 'Password must be at least 8 characters'); return v; };

// The admin account comes from .env and is reset to it on every start, so a lost password is fixed by editing .env.
async function seedAdmin() {
  await db.tx(d => {
    const { salt, hash } = hashPassword(ADMIN_PASSWORD);
    const u = d.users.find(x => x.email === ADMIN_EMAIL);
    if (u) { u.role = 'admin'; u.salt = salt; u.hash = hash; u.active = true; }
    else d.users.push({ id: randomUUID(), email: ADMIN_EMAIL, role: 'admin', name: 'FastShip Admin', city: '', mobile: '', salt, hash, active: true, createdAt: new Date().toISOString() });
  });
}

route('POST', /^\/api\/auth\/signup$/, false, async (req) => {
  throttleLogin('signup:' + (req.socket.remoteAddress ?? 'x'), 10);
  const b = await readJson(req);
  const email = emailOf(b.email), password = passOf(b.password);
  const name = str(b.business, 'Business name', 100), mobile = String(b.mobile ?? '').replace(/\D/g, '');
  if (!/^[6-9]\d{9}$/.test(mobile)) throw new HttpError(400, 'Enter a valid 10-digit mobile number');
  const contact = str(b.name, 'Your name', 100);
  return db.tx(d => {
    if (d.users.some(u => u.email === email)) throw new HttpError(409, 'An account with this email already exists. Log in instead.');
    const { salt, hash } = hashPassword(password);
    const u: User = { id: randomUUID(), email, role: 'seller', name, city: '', mobile, salt, hash, active: true, createdAt: new Date().toISOString() };
    (u as User & { contact?: string }).contact = contact;
    d.users.push(u); d.states[u.id] = blankState();
    return { token: tokens.issue(u.id, u.role), user: clean(u) };
  });
});

route('POST', /^\/api\/auth\/login$/, false, async (req) => {
  const b = await readJson(req);
  const email = emailOf(b.email), password = typeof b.password === 'string' ? b.password : '';
  throttleLogin('login:' + (req.socket.remoteAddress ?? 'x') + ':' + email);
  const u = await db.read(d => d.users.find(x => x.email === email));
  // Same message for unknown email and wrong password, so nobody can probe which emails exist.
  if (!u || !checkPassword(password, u.salt, u.hash)) throw new HttpError(401, 'Wrong email or password');
  if (!u.active) throw new HttpError(403, 'This account is suspended. Contact support@shipxpeed.com');
  if (b.role && b.role !== u.role) throw new HttpError(401, u.role === 'admin' ? 'This is an admin account. Use the Admin tab.' : 'This is a seller account. Use the Seller tab.');
  return { token: tokens.issue(u.id, u.role), user: clean(u) };
});

// ---- seller: own data ----
const num0 = (v: unknown) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };
route('GET', /^\/api\/state$/, 'seller', async (_r, _u, _p, who) => db.read(d => {
  const u = d.users.find(x => x.id === who.uid)!, st = d.states[who.uid] ?? blankState();
  return { user: clean(u), ...st, cards: d.cards[who.uid] ?? {} };
}));

route('PUT', /^\/api\/state$/, 'seller', async (req, _u, _p, who) => {
  const b = await readJson(req, 3_000_000);
  return db.tx(d => {
    const st = d.states[who.uid] ??= blankState();
    if (!Array.isArray(b.orders) || b.orders.length > 20000 || !b.orders.every(Array.isArray)) throw new HttpError(400, 'orders must be a list');
    if (!Array.isArray(b.ndr) || !b.ndr.every(Array.isArray)) throw new HttpError(400, 'ndr must be a list');
    st.orders = b.orders; st.ndr = b.ndr;
    if (b.docs && typeof b.docs === 'object') st.docs = Object.fromEntries(Object.entries(b.docs).slice(0, 12).map(([k, v]) => [k.slice(0, 30), v ? 1 : 0]));
    // KYC: a seller can submit (only when the panel says so), but only the admin can approve or reject.
    if (b.kycSubmit === true && ['Not started', 'Rejected', 'Changes requested'].includes(st.kyc.status)) st.kyc.status = 'Under review';
    if (st.kyc.status !== 'Approved' && b.kyc && typeof b.kyc === 'object' && b.kyc.flags && typeof b.kyc.flags === 'object')
      st.kyc.flags = Object.fromEntries(Object.entries(b.kyc.flags).slice(0, 10).map(([k, v]) => [k.slice(0, 30), v ? 1 : 0]));
    // Wallet: the seller may only add DEBITS (money out), each with a unique id so a retry never double-charges.
    // Credits are written by the admin.
    if (Array.isArray(b.ledger)) {
      const have = new Set(st.ledger.map(e => e[4]));
      for (const e of b.ledger) {
        if (!Array.isArray(e) || typeof e[4] !== 'string' || !e[4] || e[4].length > 60 || have.has(e[4])) continue;
        const debit = num0(e[2]), credit = num0(e[3]);
        if (debit > 0 && credit === 0 && st.ledger.length < 50000) { st.ledger.push([String(e[0]).slice(0, 40), String(e[1]).slice(0, 80), debit, 0, e[4]]); have.add(e[4]); }
      }
    }
    return { ok: true, balance: balanceOf(st), kycStatus: st.kyc.status };
  });
});

// ---- admin ----
function cleanCard(card: any): unknown {
  const nums = (a: unknown, n: number) => Array.isArray(a) && a.length === n && a.every(x => typeof x === 'number' && Number.isFinite(x) && x >= 0 && x < 1e6);
  if (!card || typeof card !== 'object') throw new HttpError(400, 'Bad rate card');
  if (!Array.isArray(card.rate) || card.rate.length !== 5 || !card.rate.every((z: unknown) => Array.isArray(z) && z.length === 5 && z.every(p => nums(p, 2)))) throw new HttpError(400, 'Rate card must have 5 zones with 5 slabs of [price, add 500 g]');
  if (!card.cod || !nums([card.cod.min, card.cod.pct], 2) || card.cod.pct > 100) throw new HttpError(400, 'COD fee must be a fixed amount and a percent (0 to 100)');
  if (!nums([card.rto], 1) || card.rto > 300) throw new HttpError(400, 'RTO percent is invalid');
  if (!nums(card.eta, 5)) throw new HttpError(400, 'Delivery days must be 5 numbers');
  return { rate: card.rate, cod: { min: card.cod.min, pct: card.cod.pct }, rto: card.rto, eta: card.eta.map((x: number) => Math.max(1, Math.round(x))), live: !!card.live };
}
const cleanCards = (c: any) => {
  if (!c || typeof c !== 'object') throw new HttpError(400, 'Bad cards');
  return Object.fromEntries(Object.entries(c).slice(0, 30).map(([n, v]) => [n.slice(0, 40), cleanCard(v)]));
};

route('GET', /^\/api\/admin\/state$/, 'admin', async () => db.read(d => ({
  sellers: d.users.filter(u => u.role === 'seller').map(u => {
    const st = d.states[u.id] ?? blankState();
    return { id: u.id, name: u.name, email: u.email, city: u.city, mobile: u.mobile, active: u.active, createdAt: u.createdAt, kycStatus: st.kyc.status, balance: balanceOf(st), orders: st.orders.length, docs: st.docs };
  }),
  cards: d.cards, cost: d.cost,
})));

route('PUT', /^\/api\/admin\/state$/, 'admin', async (req) => {
  const b = await readJson(req, 3_000_000);
  return db.tx(d => {
    const sellers = new Set(d.users.filter(u => u.role === 'seller').map(u => u.id));
    if (b.kyc && typeof b.kyc === 'object') for (const [id, status] of Object.entries(b.kyc)) {
      if (!sellers.has(id)) continue;
      if (!['Approved', 'Rejected', 'Changes requested', 'Under review'].includes(String(status))) throw new HttpError(400, 'Bad KYC status');
      (d.states[id] ??= blankState()).kyc.status = String(status);
    }
    if (b.active && typeof b.active === 'object') for (const [id, on] of Object.entries(b.active)) { const u = d.users.find(x => x.id === id && x.role === 'seller'); if (u) u.active = !!on; }
    if (b.cards && typeof b.cards === 'object') for (const [id, cards] of Object.entries(b.cards)) if (sellers.has(id)) d.cards[id] = cleanCards(cards) as Record<string, unknown>;
    if (b.cost && typeof b.cost === 'object') {
      const m: Record<string, number> = {};
      for (const [k, v] of Object.entries(b.cost.m ?? {})) { const n = Number(v); if (!(n >= 0 && n <= 100)) throw new HttpError(400, 'Margin must be 0 to 100'); m[k.slice(0, 40)] = n; }
      d.cost = { c: cleanCards(b.cost.c) as Record<string, unknown>, m };
    }
    return { ok: true };
  });
});

// Until a payment gateway is connected, the admin credits a seller's wallet after receiving the money.
route('POST', /^\/api\/admin\/wallet$/, 'admin', async (req) => {
  const b = await readJson(req);
  const amount = num(b.amount, 'amount'), ref = str(b.ref, 'reference', 80);
  if (amount > 1_000_000) throw new HttpError(400, 'Amount is too large');
  return db.tx(d => {
    const u = d.users.find(x => x.id === b.sellerId && x.role === 'seller'); if (!u) throw new HttpError(404, 'Seller not found');
    const st = d.states[u.id] ??= blankState();
    st.ledger.push(['Recharge', ref, 0, amount, randomUUID()]);
    return { balance: balanceOf(st) };
  });
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

route('POST', /^\/api\/rates$/, 'admin', async (req) => {
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

// Sellers can only ship with a KYC-approved account and a courier the admin made live for them.
async function mayShip(who: Who, code: string) {
  if (who.role === 'admin') return;
  const name = code.charAt(0).toUpperCase() + code.slice(1);
  const ok = await db.read(d => (d.states[who.uid]?.kyc.status === 'Approved') && !!(d.cards[who.uid]?.[name] as { live?: boolean } | undefined)?.live);
  if (!ok) throw new HttpError(403, `${name} is not enabled for your account yet. Your KYC must be approved and the courier switched on by FastShip.`);
}
const mine = (who: Who, s: ShipmentRecord) => who.role === 'admin' || s.ownerId === who.uid;

route('POST', /^\/api\/shipments$/, true, async (req, _u, _p, who) => {
  const b = await readJson(req);
  const c = courier(b.courier);
  await mayShip(who, c.code);
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
    const existing = rows.find(r => r.ownerId === who.uid && r.orderId === orderId && r.courier === c.code && r.dryRun === dryRun && r.status !== 'cancelled');
    if (existing) return { shipment: existing, reused: true };
    const result = await c.createShipment(to, parcel, { courierId: b.courierId });
    const rec: ShipmentRecord = {
      id: randomUUID(), ownerId: who.uid, orderId, courier: c.code, awb: result.awb, courierRef: result.courierRef, status: 'created', dryRun,
      createdAt: new Date().toISOString(),
      to: { name: to.name, phone: to.phone, line1: to.line1, city: to.city, state: to.state, pincode: to.pincode },
      parcel: { paymentMode: payment, declaredValue, codAmount: parcel.codAmount, weightGrams: parcel.weightGrams, productDescription: parcel.productDescription, quantity: parcel.quantity },
    };
    rows.push(rec);
    return { shipment: rec, reused: false, notice: dryRun ? 'DRY RUN: nothing was booked at the courier. Set DELHIVERY_DRY_RUN=false to book real shipments.' : undefined };
  });
});

route('GET', /^\/api\/shipments$/, true, async (_r, _u, _p, who) => (await store.list()).filter(s => mine(who, s)));

route('GET', /^\/api\/shipments\/([\w-]+)$/, true, async (_r, _u, [id], who) => {
  const s = await store.get(id); if (!s || !mine(who, s)) throw new HttpError(404, 'Shipment not found'); return s;
});

route('POST', /^\/api\/shipments\/([\w-]+)\/cancel$/, true, async (_r, _u, [id], who) => store.tx(async rows => {
  const s = rows.find(r => r.id === id); if (!s || !mine(who, s)) throw new HttpError(404, 'Shipment not found');
  if (s.status === 'cancelled') return s;
  await courier(s.courier).cancelShipment(s.awb, s.courierRef);
  s.status = 'cancelled'; s.cancelledAt = new Date().toISOString();
  return s;
}));

route('GET', /^\/api\/shipments\/([\w-]+)\/label$/, true, async (_r, _u, [id], who) => {
  const s = await store.get(id); if (!s || !mine(who, s)) throw new HttpError(404, 'Shipment not found');
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
    let who: Who = { uid: '', role: 'seller' };
    if (r.auth) {
      const h = req.headers.authorization ?? '';
      const t = tokens.verify(h.startsWith('Bearer ') ? h.slice(7) : undefined);
      if (!t) return send(res, 401, { error: 'Log in first' });
      const u = await db.read(d => d.users.find(x => x.id === t.uid));
      if (!u || !u.active) return send(res, 401, { error: 'Log in first' });
      if (r.auth !== 'any' && u.role !== r.auth) return send(res, 403, { error: 'Not allowed for your account' });
      who = { uid: u.id, role: u.role };
    }
    const m = url.pathname.match(r.re)!;
    send(res, 200, await r.fn(req, url, m.slice(1), who));
  } catch (e) {
    if (e instanceof HttpError) return send(res, e.status, { error: e.message });
    if (e instanceof CourierError) return send(res, 502, { error: `Courier error: ${e.message}`, retryable: e.retryable });
    console.error('Unhandled', (e as Error).message);
    send(res, 500, { error: 'Something went wrong on the server' });
  }
});
await seedAdmin();
server.listen(PORT, () => {
  console.log(`FastShip server on http://localhost:${PORT}  (panel: http://localhost:${PORT}/app/)`);
  console.log(`Couriers: ${[...couriers.keys()].join(', ') || 'none configured'}`);
  const d = (couriers.get('delhivery') as any)?.isDryRun;
  if (d !== undefined) console.log(d ? 'Delhivery is in DRY RUN mode: nothing is booked. Set DELHIVERY_DRY_RUN=false for real bookings.' : 'Delhivery is LIVE: shipments will be booked for real.');
  for (const i of setupIssues()) console.log('FIX NEEDED: ' + i);
});
