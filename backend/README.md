# FastShip backend: courier layer (Delhivery first)

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

## Run the server (create shipments and assign to Delhivery)

Needs Node.js 20 or newer.

```
cd backend
npm install
cp .env.example .env     # then edit .env (see below)
npm run build
npm start
```

Open http://localhost:3000/app/ and log in. On a "Ready to ship" order click **Ship now**,
enter the ADMIN_PASSWORD from `.env`, check the details, and press **Create shipment**.

Fill these in `.env` first:

| Setting | What it is |
| --- | --- |
| ADMIN_PASSWORD | Your own password for the server, 8+ characters |
| SESSION_SECRET | Any random text, 32+ characters |
| DELHIVERY_API_TOKEN | Your Delhivery token (use a NEW one, never share it in chat) |
| DELHIVERY_ENV | `staging` to practice, `production` for real |
| DELHIVERY_PICKUP_LOCATION | Warehouse name exactly as in Delhivery (case sensitive) |
| DELHIVERY_SELLER_GST_TIN | Your GST number |
| DELHIVERY_HSN_CODE | HSN code of what you ship |
| DELHIVERY_DRY_RUN | `true` = nothing is booked (safe). Set `false` to book real shipments |

Safety: with DRY_RUN on, the server builds the shipment but never calls Delhivery's booking API.
A second request for the same order returns the first shipment, so nothing is booked twice.
The Delhivery adapter is built from public docs and has not been run against the live API from
this project yet. Do the first real booking with one test order and check it in the Delhivery portal.

Tested here against a local pretend Delhivery server: login, validation, dry run, live booking,
no double booking, cancel, static files, path traversal.

## Rate cards

All couriers are priced by one formula in `src/rates/ratecard.ts` (zone A to E, 500 g slabs,
COD fee = larger of a minimum or a percent, RTO as a percent of freight, 18% GST). A courier is
just a rate card (data). Seller price = courier cost x (1 + margin %), where the margin is the
seller's own override or the courier default. Run the checks with `npm run test:rates`.
The numbers in the panel are SAMPLE rates until you load each courier's real rate card.
Admin edits in the panel are in memory only for now; saving them to a database is the next step.
