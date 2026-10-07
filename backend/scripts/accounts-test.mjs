// Starts the built server on a temp data folder and exercises accounts, roles, KYC, rates and wallet rules.
import { spawn } from 'node:child_process'; import { mkdtempSync } from 'node:fs'; import { tmpdir } from 'node:os'; import path from 'node:path';
const dir = mkdtempSync(path.join(tmpdir(), 'fs-')), PORT = 3411, B = `http://localhost:${PORT}`;
const env = { ...process.env, PORT, DATA_DIR: dir, ADMIN_EMAIL: 'bashu@test.com', ADMIN_PASSWORD: 'adminpass1', SESSION_SECRET: 'x'.repeat(40), DELHIVERY_API_TOKEN: 'fake', DELHIVERY_DRY_RUN: 'true', SITE_DIR: path.resolve('../site') };
let srv = null, fails = 0;
const start = async () => { srv = spawn('node', ['dist/server.js'], { env, stdio: 'ignore' }); for (let i = 0; i < 50; i++) { try { await fetch(B + '/api/health'); return } catch { await new Promise(r => setTimeout(r, 100)) } } throw new Error('server did not start') };
const stop = () => new Promise(r => { srv.on('exit', r); srv.kill() });
const api = async (m, u, b, t) => { const r = await fetch(B + u, { method: m, headers: { 'Content-Type': 'application/json', ...(t ? { Authorization: 'Bearer ' + t } : {}) }, body: b ? JSON.stringify(b) : undefined }); let d = null; try { d = await r.json() } catch { } return { s: r.status, d } };
const ok = (n, c, x = '') => { if (!c) fails++; console.log((c ? 'PASS ' : 'FAIL ') + n + (c ? '' : '  ' + JSON.stringify(x))) };
await start();
try {
  let r = await api('POST', '/api/auth/login', { email: 'bashu@test.com', password: 'wrong' }); ok('wrong admin password rejected', r.s === 401, r);
  r = await api('POST', '/api/auth/login', { email: 'bashu@test.com', password: 'adminpass1', role: 'admin' }); ok('admin login', r.s === 200 && r.d.user.role === 'admin', r); const A = r.d.token;
  r = await api('POST', '/api/auth/signup', { email: 'a@shop.in', password: 'short', business: 'Shop', mobile: '9876543210', name: 'A' }); ok('short password rejected', r.s === 400, r);
  r = await api('POST', '/api/auth/signup', { email: 'a@shop.in', password: 'password1', business: 'A Shop', mobile: '9876543210', name: 'Asha' }); ok('seller signup', r.s === 200 && r.d.user.role === 'seller', r); const S = r.d.token, SID = r.d.user.id;
  r = await api('POST', '/api/auth/signup', { email: 'A@shop.in', password: 'password1', business: 'A Shop', mobile: '9876543210', name: 'Asha' }); ok('duplicate email rejected', r.s === 409, r);
  r = await api('POST', '/api/auth/login', { email: 'a@shop.in', password: 'password1', role: 'admin' }); ok('seller cannot use admin tab', r.s === 401, r);
  r = await api('GET', '/api/state'); ok('state needs login', r.s === 401, r);
  r = await api('GET', '/api/admin/state', null, S); ok('seller blocked from admin', r.s === 403, r);
  r = await api('GET', '/api/state', null, A); ok('admin blocked from seller state', r.s === 403, r);
  r = await api('POST', '/api/rates', { fromPin: '110001', toPin: '400001', weightGrams: 500, paymentMode: 'Prepaid' }, S); ok('seller cannot see courier cost rates', r.s === 403, r);
  r = await api('GET', '/api/state', null, S); ok('new seller blank', r.s === 200 && r.d.orders.length === 0 && r.d.kyc.status === 'Not started' && Object.keys(r.d.cards).length === 0, r);
  // seller saves orders, tries to approve own KYC and credit own wallet
  const orders = [['FS-1', 'Ravi', 'Pune 411001', 'Prepaid', 500, '—', '—', 'Ready to ship']];
  r = await api('PUT', '/api/state', { orders, ndr: [], docs: { PAN: 1 }, kyc: { status: 'Approved', flags: { Business: 1 } }, ledger: [['Recharge', 'FAKE', 0, 99999, 'u0'], ['Shipment debit', 'FS-1', 50, 0, 'u1'], ['Shipment debit', 'FS-1', 50, 0, 'u1']] }, S);
  ok('seller save works', r.s === 200, r); ok('seller cannot self-approve KYC', r.d.kycStatus === 'Not started', r.d); ok('seller cannot credit own wallet; debit allowed once even if resent', r.d.balance === -50, r.d);
  r = await api('PUT', '/api/state', { orders, ndr: [], docs: {}, kyc: { status: 'Under review', flags: {} }, kycSubmit: true }, S); ok('seller can submit KYC', r.d.kycStatus === 'Under review', r.d);
  r = await api('GET', '/api/admin/state', null, A); const row = r.d.sellers.find(x => x.id === SID); ok('admin sees seller + status', row && row.kycStatus === 'Under review' && row.orders === 1, r.d);
  // shipping blocked until approved and courier live
  const ship = { courier: 'delhivery', orderId: 'FS-1', to: { name: 'Ravi', phone: '9876543210', line1: 'Road 1', city: 'Pune', state: 'Maharashtra', pincode: '411001' }, parcel: { paymentMode: 'Prepaid', declaredValue: 500, weightGrams: 500, productDescription: 'Shirt', quantity: 1 } };
  r = await api('POST', '/api/shipments', ship, S); ok('shipping blocked before approval', r.s === 403, r);
  const rate = [0, 1, 2, 3, 4].map(() => [0, 1, 2, 3, 4].map(() => [50, 20]));
  const card = { rate, cod: { min: 30, pct: 2 }, rto: 90, eta: [2, 3, 4, 5, 6], live: true };
  r = await api('PUT', '/api/admin/state', { kyc: { [SID]: 'Approved' }, cards: { [SID]: { Delhivery: card, DTDC: { ...card, live: false } } }, cost: { c: { Delhivery: card }, m: { Delhivery: 18 } } }, A); ok('admin approves KYC and sets live rates', r.s === 200, r);
  r = await api('PUT', '/api/admin/state', { cards: { [SID]: { Delhivery: { ...card, cod: { min: 30, pct: 500 } } } } }, A); ok('bad COD percent rejected', r.s === 400, r);
  r = await api('PUT', '/api/admin/state', { cards: { [SID]: { Delhivery: { ...card, rate: [[1]] } } } }, A); ok('malformed rate card rejected', r.s === 400, r);
  r = await api('GET', '/api/state', null, S); ok('seller sees approved + live card', r.d.kyc.status === 'Approved' && r.d.cards.Delhivery.live === true && r.d.cards.DTDC.live === false && r.d.cards.Delhivery.rate[0][0][0] === 50, r.d);
  r = await api('PUT', '/api/state', { orders, ndr: [], docs: {}, kyc: { status: 'Under review', flags: {} }, kycSubmit: true }, S); ok('approved KYC cannot be reverted by seller', r.d.kycStatus === 'Approved', r.d);
  r = await api('POST', '/api/shipments', ship, S); ok('shipping works when approved + live (dry run)', r.s === 200 && r.d.shipment.dryRun === true, r);
  r = await api('POST', '/api/shipments', { ...ship, courier: 'bigship' }, S); ok('unconfigured/disabled courier blocked', r.s === 400 || r.s === 403, r);
  // second seller cannot see first seller's shipment
  r = await api('POST', '/api/auth/signup', { email: 'b@shop.in', password: 'password2', business: 'B Shop', mobile: '9123456780', name: 'Bela' }); const S2 = r.d.token;
  r = await api('GET', '/api/shipments', null, S2); ok("other seller sees none of A's shipments", r.s === 200 && r.d.length === 0, r);
  r = await api('GET', '/api/shipments', null, S); ok('owner sees own shipment', r.d.length === 1, r);
  r = await api('POST', '/api/admin/wallet', { sellerId: SID, amount: 5000, ref: 'UTR123' }, A); ok('admin credits wallet', r.s === 200 && r.d.balance === 4950, r);
  r = await api('POST', '/api/admin/wallet', { sellerId: SID, amount: 5000, ref: 'x' }, S); ok('seller cannot credit wallet via API', r.s === 403, r);
  r = await api('PUT', '/api/admin/state', { active: { [SID]: false } }, A); r = await api('GET', '/api/state', null, S); ok('suspended seller locked out', r.s === 401, r);
  r = await api('POST', '/api/auth/login', { email: 'a@shop.in', password: 'password1' }); ok('suspended seller cannot log in', r.s === 403, r);
  await stop(); await start();
  r = await api('POST', '/api/auth/login', { email: 'b@shop.in', password: 'password2' }); ok('data survives restart', r.s === 200, r);
  r = await api('POST', '/api/auth/login', { email: 'bashu@test.com', password: 'adminpass1' }); r = await api('GET', '/api/admin/state', null, r.d.token); ok('rates + sellers survive restart', r.d.sellers.length === 2 && r.d.cards[SID]?.Delhivery && r.d.cost?.m?.Delhivery === 18, r.d);
  for (let i = 0; i < 10; i++) r = await api('POST', '/api/auth/login', { email: 'zz@x.in', password: 'nope' }); ok('login attempts throttled', r.s === 429, r);
} finally { try { await stop() } catch { } }
console.log(fails ? `\n${fails} FAILED` : '\nALL PASSED'); process.exit(fails ? 1 : 0);
