# Shipxpeed

Multi-courier shipping aggregator for Indian e-commerce sellers.

| Folder | What it is |
| --- | --- |
| `site/` | Static landing site (`index.html`) and the seller/admin panel prototype (`app/index.html`). Deploy to Hostinger `public_html`. See `site/DEPLOY.md`. |
| `backend/` | Courier adapter layer (Delhivery, Bigship) with read-only smoke tests. See `backend/README.md`. |

## Status
- Panel is a front-end prototype on sample data (rates are labelled SANDBOX / MOCK).
- Delhivery and Bigship adapters are written but **integration pending**: not yet tested against the live APIs.
- Not built yet: API server, database, wallet ledger, webhooks, job queue.

## Secrets
Never commit credentials. Courier tokens live in `backend/.env` (see `backend/.env.example`).
