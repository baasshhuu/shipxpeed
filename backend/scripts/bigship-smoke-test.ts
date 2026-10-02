// Read-only checks against Bigship. Places no orders.
// Run: npx tsx --env-file=.env scripts/bigship-smoke-test.ts [pickupPin] [deliveryPin]
import { BigshipAdapter } from '../src/couriers/bigship';

const [fromPin = process.env.BIGSHIP_PICKUP_PINCODE ?? '110001', toPin = '400001'] = process.argv.slice(2);

async function step(name: string, fn: () => Promise<unknown>) {
  try { console.log(`PASS ${name}`, JSON.stringify(await fn(), null, 2)); }
  catch (e: any) { console.log(`FAIL ${name}: ${e.message} ${e.status ?? ''} ${JSON.stringify(e.body ?? '')}`); }
}

(async () => {
  const { BIGSHIP_USERNAME: u, BIGSHIP_PASSWORD: p, BIGSHIP_ACCESS_KEY: k } = process.env;
  if (!u || !p || !k) throw new Error('Set BIGSHIP_USERNAME, BIGSHIP_PASSWORD and BIGSHIP_ACCESS_KEY in .env first');
  const bs = new BigshipAdapter({ username: u, password: p, accessKey: k, pickupWarehouseId: 0, pickupPincode: fromPin, dryRun: true });
  await step('login + profile (wallet)', async () => { const d = await bs.getProfile(); return { name: d?.firstName, wallet: d?.userWallet, outboundEnabled: d?.is_outbound_service_enabled }; });
  await step('warehouses (copy the id you want into BIGSHIP_PICKUP_WAREHOUSE_ID)', async () => {
    const d = await bs.listWarehouses('local');
    return (d?.warehouse ?? []).map((w: any) => ({ id: w.warehouseId, name: w.warehouseName, pin: w.pincode }));
  });
  await step(`rates ${fromPin} -> ${toPin}, 500g prepaid`, async () => (await bs.calculateRates(fromPin, toPin, 500, 'Prepaid')).map(({ raw, ...r }) => r));
  await step('rates COD Rs 999', async () => (await bs.calculateRates(fromPin, toPin, 500, 'COD', 999)).map(({ raw, ...r }) => r));
})();
