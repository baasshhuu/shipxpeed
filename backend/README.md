# Shipxpeed backend: courier layer (Delhivery first)

## Run the read-only check

```bash
npm init -y && npm i -D tsx typescript @types/node   # Node 20+
cp .env.example .env                                  # then fill in the token
npx tsx --env-file=.env scripts/delhivery-smoke-test.ts 110001 400001
```

It checks pincode serviceability and prepaid/COD rates. Pass a real AWB as the
third argument to test tracking. Nothing is created or booked while
`DELHIVERY_DRY_RUN=true`.

## Before real shipments

1. Rotate the token (it was shared in chat) and put the new one only in `.env`.
2. Set `DELHIVERY_PICKUP_LOCATION` to your exact registered warehouse name.
3. Compare each `VERIFY` line in `src/couriers/delhivery.ts` with the samples in
   the Delhivery One developer portal; fix field names if they differ.
4. Create one test shipment with `DELHIVERY_DRY_RUN=false`, then cancel it.

## Design rules

- Order code only talks to `CourierAdapter`; never import a courier directly.
- `calculateRate` returns courier cost. Margin is added by the pricing engine,
  and the seller UI never sees this value.
- Create/cancel/pickup calls do not auto-retry. The job queue retries them with
  an idempotency check so a retry cannot double-book.

## Next

Tracking webhook endpoint (status normalization into NDR/RTO), shipment-creation
job with retry and fallback courier, then the next courier adapter.

## Bigship

Credentials (`BIGSHIP_USERNAME`, `BIGSHIP_PASSWORD`, `BIGSHIP_ACCESS_KEY`) go in `.env` only.

```bash
npx tsx --env-file=.env scripts/bigship-smoke-test.ts 110001 400001
```

The test logs in, shows your wallet, lists your saved warehouses (use the id for
`BIGSHIP_PICKUP_WAREHOUSE_ID`) and fetches prepaid and COD rates. It places no orders.

Notes:
- Bigship fronts many carriers. `calculateRates()` returns one quote per carrier; pass
  `opts.courierId` to `createShipment()` to choose one, otherwise the cheapest is used.
- Creating a shipment is three calls (draft order, quotes, place order). Save
  `ShipmentResult.courierRef`: Bigship needs it to cancel and to fetch labels.
- Bigship is production only, so keep `BIGSHIP_DRY_RUN=true` until the smoke test passes.
- `VERIFY` comments in `bigship.ts` mark where the PDF is ambiguous (weight units, GST in
  totals, invoiceType, GET bodies, category ids).
