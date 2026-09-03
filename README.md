# ServerDashboard

Laravel 10 dashboard for monitoring WordPress plugin updates, website traffic, and AWS EC2 patch status.

## Included Features

- Session login with `admin` and `viewer` roles.
- Admin user management and role assignment.
- Website traffic meters and a multi-site traffic chart.
- Secure WordPress plugin update ingestion.
- AWS EC2 and Systems Manager patch state sync.
- CSRF protection for browser actions, rate limiting, request validation, hashed passwords, role middleware, monitor-key authentication, and browser security headers.

## Run Locally

This workspace is configured for SQLite and XAMPP PHP.

```powershell
& "C:\xampp\php\php.exe" artisan migrate:fresh --seed
& "C:\xampp\php\php.exe" artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000/login`.

## Public Deployment URL

Reporter endpoints are generated from `DASHBOARD_PUBLIC_URL`, not from a fixed local address. Keep the local values while developing:

```env
APP_URL=http://127.0.0.1:8000
DASHBOARD_PUBLIC_URL=http://127.0.0.1:8000
DASHBOARD_FORCE_HTTPS=false
```

Before deployment, update the environment on the server to the public HTTPS domain and rebuild Laravel caches:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dashboard.example.com
DASHBOARD_PUBLIC_URL=https://dashboard.example.com
DASHBOARD_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
```

```powershell
& "C:\xampp\php\php.exe" artisan config:cache
& "C:\xampp\php\php.exe" artisan route:cache
& "C:\xampp\php\php.exe" artisan view:cache
```

Use the final `https://` address in the dashboard before registering WordPress sites. Newly generated reporter endpoints will then use that public domain automatically. If the app runs behind a load balancer or reverse proxy, configure the proxy to pass `X-Forwarded-Proto: https` and restrict access to the Laravel server so only the proxy can reach it.

`127.0.0.1` and `localhost` are usable only when WordPress and ServerDashboard run on the same machine. A public WordPress website cannot reach the dashboard on your computer through either address.

Default seeded accounts:

- `admin@example.com` / `Admin#12345`
- `viewer@example.com` / `Viewer#12345`

Change these credentials before deployment.

## Add a WordPress Site and Monitor Plugin Updates

1. Sign in as an `admin` and use **WordPress Sites** to add the site name and WordPress URL.
2. Download the generated reporter ZIP from the dashboard and install it on the WordPress site through **Plugins > Add New > Upload Plugin**.
3. In the WordPress admin, open **Settings > ServerDashboard Reporter**.
4. Copy the matching **Reporter endpoint** and **Site token** shown in the dashboard into the reporter settings.
5. Select **Send plugin report now** to confirm the connection. The plugin then posts a report every day through WordPress cron.

The reporter uses WordPress's native plugin and core update checks. It sends the installed version, newest available version, and whether an update is available for every installed plugin, plus the same information for WordPress core. It also hooks into `wp_login` to immediately report the username and client IP address of every wp-admin login to the dashboard.

## Reporter Security

The included reporter ZIP is version 1.2.0. It requires an HTTPS endpoint by default and will not submit reports to plain HTTP URLs in production. During development, an administrator can explicitly enable **Development HTTP endpoint** in the WordPress reporter settings, save, and use an `http://` endpoint temporarily. Disable that option before production because HTTP does not protect report metadata in transit.

Enabling this option only removes the reporter's HTTPS validation. It does not make `127.0.0.1` reachable from another machine: a public WordPress site still needs an address that routes to the dashboard development machine, such as a LAN hostname, a temporary HTTPS tunnel, or the final public deployment domain. It provides the following protections:

- The site token is encrypted before it is stored in WordPress and is never sent in an HTTP header or request body.
- Each report contains an HMAC-SHA256 signature over the exact JSON payload, a cryptographically random nonce, and a timestamp.
- The dashboard accepts reports only within five minutes and records every nonce, preventing replay of a captured request.
- HTTPS certificate validation is enforced, redirects are disabled, unsafe URLs are rejected, and the dashboard response is size-limited.
- Only WordPress administrators can change settings or run a manual report; both settings and manual actions are nonce-protected.
- The plugin retains a redacted, local audit log of recent report outcomes. It never records the token or request body.

The dashboard encrypts usable site tokens at rest and stores only a non-reversible hash alongside them. It shows a new token only once, immediately after a site is added or a token is rotated.

If a site token is exposed or a WordPress site is compromised, use **Rotate token** in the dashboard immediately, save the new token in the WordPress reporter settings, and use **Disable reporting** while investigating. A rotated token invalidates all previous signed reports.

Sites using the original 1.0 reporter must install the 1.1 ZIP and save the reporter settings again; the original plaintext token setting is intentionally not reused.

## Traffic Ingest

```bash
curl -X POST http://127.0.0.1:8000/ingest/traffic \
  -H "Content-Type: application/json" \
  -H "X-Monitor-Key: YOUR_INGEST_KEY" \
  -d '{"siteName":"wordpress-main","visits":842,"recordedAt":"2026-08-14T03:00:00Z"}'
```

## AWS EC2 Patches

For live data, set `AWS_SYNC_USE_MOCK=false`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and `AWS_DEFAULT_REGION` in `.env`. The AWS identity needs `ec2:DescribeInstances` and `ssm:DescribeInstancePatchStates` permissions. Sign in as an admin and select **Sync EC2 updates**.

`AWS_SYNC_USE_MOCK=true` enables the safe development fallback shown by the seeded dashboard.# serverDashboard
