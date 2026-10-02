# Deploy Shipxpeed site to Hostinger

Contents: `index.html` (landing site) and `app/index.html` (seller and admin panel).
Both are static files, so any Hostinger web hosting plan works.

## Option A: upload by hand (2 minutes)
1. hPanel -> Websites -> your site -> File Manager -> `public_html`.
2. Upload `index.html` and the `app` folder (or upload shipxpeed-site.zip and Extract here).
3. Open your domain. The landing page links to `/app/` for the panel.

## Option B: auto-deploy from GitHub
1. Put this folder's contents in a GitHub repo.
2. hPanel -> Websites -> Git -> connect the repo, branch `main`, directory `public_html`.
3. Every push to `main` redeploys.

## Not included
The backend (courier adapters, database, webhooks) needs Node.js hosting or a VPS.
Keep courier tokens in the server's `.env`, never in these static files.
