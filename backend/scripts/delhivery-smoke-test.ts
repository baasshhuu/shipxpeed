// Read-only checks against Delhivery. Creates nothing, cancels nothing.
// Run: npx tsx --env-file=.env scripts/delhivery-smoke-test.ts [pickupPin] [deliveryPin] [awb]
import { buildCouriers } from '../src/couriers';

const [fromPin = '110001', toPin = '400001', awb] = process.argv.slice(2);

async function step(name: string, fn: () => Promise<unknown>) {
  try { console.log(`PASS ${name}`, JSON.stringify(await fn(), null, 2)); }
  catch (e: any) { console.log(`FAIL ${name}: ${e.message} ${e.status ?? ''} ${JSON.stringify(e.body ?? '')}`); }
}

(async () => {
  const dl = buildCouriers().get('delhivery');
  if (!dl) throw new Error('Set DELHIVERY_API_TOKEN in .env first');
  await step(`serviceability ${toPin}`, async () => { const { raw, ...r } = await dl.serviceability(toPin); return r; });
  await step(`rate ${fromPin} -> ${toPin}, 500g prepaid`, async () => { const { raw, ...r } = await dl.calculateRate(fromPin, toPin, 500, 'Prepaid'); return r; });
  await step('rate COD Rs 999', async () => { const { raw, ...r } = await dl.calculateRate(fromPin, toPin, 500, 'COD', 999); return r; });
  if (awb) await step(`tracking ${awb}`, async () => { const { raw, ...r } = await dl.trackShipment(awb); return r; });
})();
