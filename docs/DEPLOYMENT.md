# Deployment Runbook — evotech-sys.com (VPS + CloudPanel + Cloudflare)

> **Scope.** How to bring the EVOTECH platform online on your Contabo/VPS server that
> runs **CloudPanel**, using the domain **`evotech-sys.com`** (registered at Cloudflare).
> Covers both repos: **`evotech-core`** (Laravel 12 API) and **`evotech-web`** (Next.js 16
> website + dashboard). Written to *not* disturb any existing site already on the box
> (e.g. the restaurant system) — every service gets its own CloudPanel site + port.
>
> This is an operational runbook, not architecture. The binding architecture is
> `docs/ARCHITECTURE.md`; the decoupled/self-hosted decision is ADR 0001.

---

## 0. Subdomain plan

We agreed to split the platform across subdomains. Final mapping:

| Subdomain | Serves | Repo | Backed by |
|---|---|---|---|
| `evotech-sys.com` + `www.evotech-sys.com` | Public marketing website | `evotech-web` | Next.js (Node) via PM2 |
| `app.evotech-sys.com` | Authenticated dashboard | `evotech-web` (same app, `/[locale]/dashboard`) | Same Next.js process |
| `api.evotech-sys.com` | REST API (`/api/v1/...`) | `evotech-core` | PHP-FPM (Laravel) |

Notes:
- **One Next.js process serves both `www` and `app`.** The dashboard is a route group
  inside the same app, so `app.evotech-sys.com` reverse-proxies to the *same* Node port
  as the marketing site. (You can split them into two PM2 processes later if you want
  independent scaling — not needed for launch.)
- The API is a normal CloudPanel **PHP site** pointed at Laravel's `public/` dir.
- Auth is **token-based (Sanctum bearer tokens)**, not cookie/session — so the browser
  never needs a shared parent cookie domain. Cross-subdomain works with plain CORS +
  `Authorization` header. This keeps the subdomain split simple.

---

## 1. Prerequisites (one-time, on the server)

Confirm these exist in CloudPanel / on the box:

- [ ] **CloudPanel** reachable (`https://<server-ip>:8443`).
- [ ] **Node.js 20 LTS or 22** installed (Next 16 requires Node ≥ 20.9).
      Install via CloudPanel's Node.js manager or `nvm`. Check: `node -v`.
- [ ] **PM2** installed **as the site user** (not root — see box below).
- [ ] **PHP 8.3** (8.4 matches CI; 8.2 is the floor) available in CloudPanel, with
      extensions: `bcmath, ctype, curl, dom, fileinfo, mbstring, openssl, pdo, pdo_mysql, tokenizer, xml`.
- [ ] **Composer 2** on PATH: `composer -V`.
- [ ] **MySQL 8** (CloudPanel bundles MariaDB/MySQL — either is fine per ADR 0003).
- [ ] **Git** + a deploy key or HTTPS access to both repos (see §9).

> **Installing PM2 without root (CloudPanel site users are unprivileged).**
> `npm install -g pm2` as a CloudPanel site user fails with `EACCES … mkdir
> '/usr/lib/node_modules/pm2'` — the site user can't write to the system-wide global
> dir, and that's by design (you don't want the site running as root). Fix by giving
> npm a global folder **inside the site user's home**, then installing there:
>
> ```bash
> # run as the site user (e.g. evotech-web / evotech-sys)
> mkdir -p ~/.npm-global
> npm config set prefix ~/.npm-global
> echo 'export PATH="$HOME/.npm-global/bin:$PATH"' >> ~/.bashrc
> export PATH="$HOME/.npm-global/bin:$PATH"
> npm install -g pm2
> pm2 -v
> ```
>
> PM2 then runs as the site user, which is exactly what we want. (If Node was
> installed via `nvm` instead of system-wide apt, global installs already land in a
> writable per-user dir and you won't hit this at all.)
>
> **Alternative — skip PM2 entirely:** CloudPanel's native **Node.js site** type
> supervises the app process for you (start/restart/boot-persistence) without PM2 or
> sudo. If you'd rather not manage PM2, create the web site as a *Node.js* site
> (§4.1) instead of a Reverse Proxy site and let CloudPanel run `npm run start`.

---

## 2. Cloudflare DNS

In the Cloudflare dashboard for `evotech-sys.com` → **DNS → Records**, create four `A`
records all pointing at your **VPS public IP**:

| Type | Name | Content | Proxy |
|---|---|---|---|
| A | `@`   | `<VPS_IP>` | see note |
| A | `www` | `<VPS_IP>` | see note |
| A | `app` | `<VPS_IP>` | see note |
| A | `api` | `<VPS_IP>` | see note |

**Proxy (orange vs grey cloud) — recommended launch path:**

1. Set all four to **DNS only (grey cloud)** first.
2. Issue SSL certs inside CloudPanel (§4) — Let's Encrypt needs the grey cloud so the
   HTTP-01 challenge reaches your server directly.
3. Once each site loads over HTTPS, **optionally** switch the records to **Proxied
   (orange cloud)** and set Cloudflare **SSL/TLS mode = Full (strict)**. Leave the
   CloudPanel Let's Encrypt cert in place as the origin cert.

> If you go orange-cloud immediately, use a **Cloudflare Origin Certificate** on each
> CloudPanel site instead of Let's Encrypt, and set SSL/TLS mode to Full (strict).
> The grey-cloud-first path is simpler for a first deploy.

---

## 3. Deploy the API — `api.evotech-sys.com` (evotech-core)

### 3.1 Create the CloudPanel site
CloudPanel → **Sites → Add Site → PHP Site (Generic / Laravel if listed)**:
- Domain: `api.evotech-sys.com`
- PHP version: **8.3** (or 8.4)
- Note the created **Site User** and its home, e.g. `/home/evotech-api/`.

### 3.2 Get the code
SSH in as the site user (CloudPanel → Site → gives SSH user):

```bash
cd /home/evotech-api/htdocs
rm -rf api.evotech-sys.com                 # remove the empty scaffold CloudPanel created
git clone https://github.com/Mohammad-Hasan-it-96/evotech-core.git api.evotech-sys.com
cd api.evotech-sys.com
composer install --no-dev --optimize-autoloader
```

### 3.3 Point the vhost at Laravel's `public/`
Laravel must be served from `public/`, never the repo root.
CloudPanel → Site → **Vhost** (or "Root Directory"): set the document root to
`/home/evotech-api/htdocs/api.evotech-sys.com/public` and ensure the Nginx location
block has Laravel's front-controller rule:

```nginx
index index.php;
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```
(CloudPanel's Laravel template already includes this; if you used the generic PHP
template, edit the vhost and save.)

### 3.4 Environment
```bash
cp .env.example .env
php artisan key:generate
```
Edit `.env` (via `nano .env` — this file is git-ignored and never committed):

```dotenv
APP_NAME=EVOTECH
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.evotech-sys.com

# MySQL (create the DB + user in CloudPanel → Databases first)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=evotech_core
DB_USERNAME=evotech_core
DB_PASSWORD=<strong-password-from-cloudpanel>

# Queue + cache (database driver needs no extra service; switch to redis later if desired)
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

# Mail (fill in your provider)
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="no-reply@evotech-sys.com"

# Payments — keep manual until Stripe is reviewed & credentialed (ADR 0009)
PAYMENTS_GATEWAY=manual
# When going live with Stripe:
# PAYMENTS_GATEWAY=stripe
# STRIPE_SECRET=sk_live_...
# STRIPE_KEY=pk_live_...
# STRIPE_WEBHOOK_SECRET=whsec_...

# Which browser origins may call the API (dashboard + site)
FRONTEND_URL=https://app.evotech-sys.com
```

### 3.5 CORS (cross-subdomain browser calls)
The dashboard (`app.evotech-sys.com`) and site (`www`) call the API on another
subdomain, so the API must allow those origins. Publish and edit the CORS config:

```bash
php artisan config:publish cors
```
In `config/cors.php` set:
```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_origins' => [
    'https://evotech-sys.com',
    'https://www.evotech-sys.com',
    'https://app.evotech-sys.com',
],
'allowed_methods' => ['*'],
'allowed_headers' => ['*'],
'supports_credentials' => false,   // token auth — no cookies needed
```
> The Stripe webhook (`/api/v1/stripe/webhook`) is server-to-server and is **not**
> subject to CORS — Stripe calls it directly, verified by HMAC signature (ADR 0009).

### 3.6 Migrate + cache + permissions
```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
chmod -R ug+rw storage bootstrap/cache
```

### 3.7 Background workers (required)
Two things must run continuously:

**a) Scheduler** (daily subscription-expiry sweep, etc.) — add a cron for the site user
(CloudPanel → Site → **Cron Jobs**, or `crontab -e`):
```cron
* * * * * cd /home/evotech-api/htdocs/api.evotech-sys.com && php artisan schedule:run >> /dev/null 2>&1
```

**b) Queue worker** (queued notifications). Simplest is PM2 (already installed for Next):
```bash
cd /home/evotech-api/htdocs/api.evotech-sys.com
pm2 start "php artisan queue:work --sleep=3 --tries=3 --max-time=3600" --name evotech-queue
```
(Alternatively use Supervisor if you prefer. Restart the worker on every deploy so it
picks up new code — see §7.)

### 3.8 TLS
CloudPanel → Site `api.evotech-sys.com` → **SSL/TLS → Let's Encrypt → Issue**.
(Requires the `api` DNS record to be **grey cloud** at issue time — see §2.)

**Smoke test:** `curl -i https://api.evotech-sys.com/up` → `200`.

---

## 4. Deploy the website + dashboard — `evotech-sys.com`, `www`, `app` (evotech-web)

The same Next.js process serves all three; we run it on an internal port with PM2 and
put CloudPanel **Reverse Proxy** sites in front.

### 4.1 Create the primary site
CloudPanel → **Sites → Add Site → Reverse Proxy** (or Node.js site type if you prefer
CloudPanel to manage the process):
- Domain: `evotech-sys.com`, add `www.evotech-sys.com` as an additional domain/alias.
- Reverse proxy target: `http://127.0.0.1:3000`.
- Note the site user, e.g. `/home/evotech-web/`.

### 4.2 Get the code + build
```bash
cd /home/evotech-web/htdocs
rm -rf evotech-sys.com
git clone https://github.com/Mohammad-Hasan-it-96/evotech-web.git evotech-sys.com
cd evotech-sys.com
npm ci
```
Create `.env.production` (git-ignored):
```dotenv
NEXT_PUBLIC_API_URL=https://api.evotech-sys.com/api
NODE_ENV=production
```
Build + start under PM2 on port 3000:
```bash
npm run build
pm2 start "npm run start" --name evotech-web -- --port 3000
# (or: PORT=3000 pm2 start npm --name evotech-web -- run start)
```

### 4.3 The `app.` subdomain (dashboard)
Create a **second** Reverse Proxy site in CloudPanel:
- Domain: `app.evotech-sys.com`
- Target: **the same** `http://127.0.0.1:3000`.

The dashboard lives at `/[locale]/dashboard` inside the app, so
`https://app.evotech-sys.com` will resolve into the Next app. If you want the bare
`app.` host to land users directly on the dashboard, add a redirect/rewrite (in
`next.config.ts` `redirects()` keyed on host, or a small Nginx rule in the CloudPanel
vhost) from `/` → `/ar/dashboard`. Not required for launch.

### 4.4 Persist PM2 across reboots
```bash
pm2 save
pm2 startup            # prints a `sudo env PATH=... pm2 startup systemd -u <site-user> ...` line
```
`pm2 startup` registers a systemd boot service, which **needs root once**:
- **Have a root/sudo login?** Copy the `sudo env PATH=… pm2 startup systemd -u <site-user> …`
  line it prints and run it as root, then back as the site user: `pm2 save`.
- **No sudo for the site user?** Skip `pm2 startup` and instead run the web app as a
  CloudPanel **Node.js site** (§4.1 alternative) so CloudPanel handles boot-restart —
  or ask whoever administers the box to run the one printed command.

### 4.5 TLS
Issue Let's Encrypt certs in CloudPanel for **both** sites: `evotech-sys.com`
(+`www`) and `app.evotech-sys.com` (grey-cloud DNS at issue time).

**Smoke test:**
- `https://evotech-sys.com` → redirects to `/ar` (Arabic default, RTL) and renders.
- `https://app.evotech-sys.com/ar/dashboard` → dashboard login.
- Dashboard login talks to `https://api.evotech-sys.com` with no CORS error (check
  browser devtools console).

---

## 5. Stripe webhook (only when going live — ADR 0009)

Once `PAYMENTS_GATEWAY=stripe` and real keys are set (§3.4):
1. Stripe Dashboard → Developers → **Webhooks → Add endpoint**:
   `https://api.evotech-sys.com/api/v1/stripe/webhook`
2. Subscribe to at least `payment_intent.succeeded`.
3. Copy the signing secret into `STRIPE_WEBHOOK_SECRET`, then
   `php artisan config:cache` and restart the queue worker.
4. Send a test event from Stripe and confirm a `200`.

Until then leave `PAYMENTS_GATEWAY=manual` — the Stripe adapter is scaffolded but not
credential-reviewed.

---

## 6. Post-deploy verification checklist

- [ ] `https://api.evotech-sys.com/up` → 200
- [ ] `https://evotech-sys.com` and `/en` both render; language switch works
- [ ] `https://www.evotech-sys.com` serves (not a cert warning)
- [ ] `https://app.evotech-sys.com` reaches the dashboard
- [ ] Dashboard login succeeds; an authenticated API call returns data (no CORS error)
- [ ] `pm2 list` shows `evotech-web` + `evotech-queue` **online**
- [ ] Scheduler cron present (`crontab -l`)
- [ ] `robots.txt` and `sitemap.xml` resolve on the web domain
- [ ] The **existing restaurant site is still up** (separate CloudPanel site untouched)

---

## 7. Redeploy / update procedure

**API (evotech-core):**
```bash
cd /home/evotech-api/htdocs/api.evotech-sys.com
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
pm2 restart evotech-queue        # workers must reload to run new code
```

**Web (evotech-web):**
```bash
cd /home/evotech-web/htdocs/evotech-sys.com
git pull
npm ci
npm run build
pm2 restart evotech-web
```

> Tip: put each of these in a `deploy.sh` in the repo so redeploys are one command.

---

## 8. Rollback

- **Web:** `git checkout <previous-tag>` → `npm ci && npm run build && pm2 restart evotech-web`.
- **API:** `git checkout <previous-tag>` → reinstall/caches. **Migrations:** prefer
  fixing forward; only `php artisan migrate:rollback` if the migration is safely
  reversible (the `payment_events`/audit ledgers are immutable — never roll those back).
- Keep the previous release reachable by tagging each deploy (`git tag deploy-YYYYMMDD`).

---

## 9. Repositories

Both repos are pushed and clonable by the server:

| Repo | Remote | Serves |
|---|---|---|
| `evotech-core` | `https://github.com/Mohammad-Hasan-it-96/evotech-core.git` | API (`api.` subdomain) |
| `evotech-web`  | `https://github.com/Mohammad-Hasan-it-96/evotech-web.git`  | site + dashboard (root/`www`/`app`) |

Both track `origin/main`. `.env*` is git-ignored in both, so secrets live only on the
server — set them per §3.4 (API) and §4.2 (web) after cloning.

---

## Appendix — port/summary map

| Service | Process | Internal port | Public host | CloudPanel site type |
|---|---|---|---|---|
| Laravel API | PHP-FPM | (unix socket) | `api.evotech-sys.com` | PHP |
| Next.js (site+dashboard) | PM2 `evotech-web` | `127.0.0.1:3000` | `evotech-sys.com`, `www`, `app` | Reverse Proxy ×2 |
| Queue worker | PM2 `evotech-queue` | — | — | — |
| Scheduler | cron `schedule:run` | — | — | — |
