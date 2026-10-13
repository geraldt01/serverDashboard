# ServerDashboard

Laravel 10 dashboard for monitoring WordPress plugin updates, website traffic, and AWS EC2 patch status.

## Included Features

- Session login with `admin` and `viewer` roles.
- Mandatory mobile authenticator MFA (TOTP) with local QR enrollment and single-use recovery codes.
- Admin user management and role assignment.
- Admin support ticketing with issue details, reporter, start/finish times, statuses, and private AWS S3 attachments.
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

### Authenticator MFA

Deploy the new code and run `php artisan migrate --force` before accepting logins. Keep the existing `APP_KEY`: MFA secrets are encrypted with it, and changing it makes those secrets unreadable. Existing password-only sessions must sign in again.

All dashboard users enroll on their next successful password login. Scan the setup QR code with Google Authenticator, Microsoft Authenticator, or a compatible TOTP app, then enter its six-digit code. A manual setup key is also available. Setup is only saved after the code is verified.

Eight recovery codes are displayed once after enrollment. Store them in a password manager or another secure offline location. Subsequent logins require the password followed by an authenticator code or one unused recovery code. There is no remembered-device bypass. Pending logins expire after ten minutes; MFA verification is limited per account and IP, and accepted authenticator codes cannot be reused. Keep the server and phone clocks synchronized.

If both the authenticator and all recovery codes are lost, a trusted server administrator must verify the user's identity outside the application before clearing that user's `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `two_factor_last_used_at`, and `remember_token` columns. This revokes their existing dashboard access and requires fresh enrollment. There is intentionally no public MFA reset endpoint.

### Support Ticketing and S3 Attachments

Sign in as an `admin` and open **Support Ticketing** in the left menu. Create a ticket with an issue title, issue details, and the name of the person who reported it. Start and finish times use the application's configured timezone (UTC by default). In-progress tickets require a start time; resolved tickets require both times, with finish at or after start. To reopen a resolved ticket, change its status; the finished time is cleared. Tickets can be searched and filtered by status.

Deploy with `composer install` and `php artisan migrate --force`. The included `league/flysystem-aws-s3-v3` dependency enables Laravel's S3 disk. Tickets without attachments work without AWS configuration; attachment uploads always use the `s3` disk regardless of `FILESYSTEM_DISK`.

1. Create a private S3 bucket in your chosen AWS region. Keep **Block all public access** enabled and **Object Ownership: Bucket owner enforced**. Enable default bucket encryption (SSE-S3); do not add a public-read bucket policy.
2. Set the following environment variables on the dashboard server. Use the bucket's actual region. These AWS settings are shared with the dashboard's existing AWS integrations, so retain their required permissions and compatible region settings.

   ```env
   AWS_DEFAULT_REGION=ap-southeast-2
   AWS_BUCKET=your-private-support-bucket
   AWS_ACCESS_KEY_ID=your-access-key-id
   AWS_SECRET_ACCESS_KEY=your-secret-access-key
   AWS_USE_PATH_STYLE_ENDPOINT=false
   ```

   Prefer an IAM role on AWS hosting instead of static credentials; leave the key and secret empty to use the AWS SDK credential provider chain. Never commit credentials. Standard AWS S3 does not need `AWS_URL` or `AWS_ENDPOINT`.
3. Attach a policy like the following to the dashboard's IAM identity, replacing `your-private-support-bucket` with your bucket name:

   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       {
         "Effect": "Allow",
         "Action": ["s3:ListBucket"],
         "Resource": "arn:aws:s3:::your-private-support-bucket",
         "Condition": {
           "StringLike": {"s3:prefix": ["support-tickets", "support-tickets/*"]}
         }
       },
       {
         "Effect": "Allow",
         "Action": ["s3:GetObject", "s3:PutObject", "s3:PutObjectAcl", "s3:DeleteObject"],
         "Resource": "arn:aws:s3:::your-private-support-bucket/support-tickets/*"
       }
     ]
   }
   ```

   Uploads specify `bucket-owner-full-control`, which is accepted by bucket-owner-enforced buckets without making files public. Delete permission is used to clean up uploads when saving fails. SSE-KMS buckets additionally require appropriate KMS permissions and key policy access.
4. Run `php artisan config:cache` after updating the server environment. Create a ticket with a small attachment, then download it from the ticket detail page to confirm bucket access.

Attachments are stored under `support-tickets/{ticket-id}/` with generated filenames. Original names and file metadata are stored in the database. Downloads are streamed through authenticated, MFA-verified, admin-only routes; no public S3 URLs are exposed. Existing files remain when new files are added. An upload failure rolls back the ticket changes and attempts to remove files uploaded during that submission; cleanup failures are logged for administrator follow-up.

If saving with attachments fails, run `php artisan config:cache` after deploying code or changing AWS environment settings, retry the upload, and inspect the configured Laravel log (by default `storage/logs/laravel.log`). The S3 disk throws exceptions so the log retains the underlying AWS error; the form deliberately shows only a generic message. For `AccessDenied`, verify the application's IAM role or credentials have the permissions above, including `s3:PutObjectAcl` for the explicit bucket-owner ACL, and check bucket policy and KMS restrictions. For region, bucket, credential, or connection errors, correct the corresponding AWS setting. Do not make the bucket public or share credentials when troubleshooting.

Each submission accepts up to five files of 10 MB each: JPG/JPEG, PNG, GIF, WebP, PDF, TXT, CSV, DOC/DOCX, XLS/XLSX, and ZIP. Set PHP `upload_max_filesize` to at least `10M`, `post_max_size` above `50M` (for example `64M`), and `max_file_uploads` to at least `5`. Match any reverse-proxy request size limit. Files are type-checked and downloaded as attachments, but not malware-scanned; scan downloaded files before opening them. Avoid bucket lifecycle rules that delete these files while ticket records are still retained.

### Monthly IT and Security Reporting

Run `php artisan migrate --force`, configure the production SMTP mailer, sign in as an admin, and open **Reporting Settings** to enter the manager's email address. The public report uses a 256-bit random bearer link; anyone who has it can view the report, so share it only with the intended recipient. Rotate the link from Reporting Settings if it is disclosed. The email is scheduled for the 5th at 08:00 in the application's configured timezone and covers the previous complete calendar month.

The report combines stored WordPress update/plugin/core/PHP reports, EC2 Systems Manager patch snapshots, five-minute uptime observations, ticket change history, user roles/MFA, and an operating guide. Uptime polling checks active WordPress site URLs and EC2 instance/system health in every enabled AWS region. An hourly read-only collector also records EC2 patch compliance across enabled regions; this does not install patches and queries only instances registered as Systems Manager managed nodes. Agent-reported server patch snapshots and package details are retained in the database for the recent patch-history table. SSM history consists of compliance observations (not individual install-command results); historical agent reports start only after the history migration is deployed. The AWS cost chart queries Cost Explorer on every report page load, grouped by region and month, for the previous six months plus current month-to-date; **Refresh costs** reloads that report. This is the freshest Cost Explorer result available, not minute-by-minute billing: AWS processes and updates cost data periodically, often with a delay of 24 hours or more. The app's AWS identity needs `ec2:DescribeRegions`, `ec2:DescribeInstances`, `ec2:DescribeInstanceStatus`, `ssm:DescribeInstanceInformation`, `ssm:DescribeInstancePatchStates`, and `ce:GetCostAndUsage` (plus existing permissions for the manual patch-install action). Without those permissions, the report labels costs or monitoring data unavailable rather than inventing values.

To enable the cost chart, first enable Cost Explorer for the AWS account in **Billing and Cost Management → Cost Explorer**. Then attach this least-privilege read policy to the same IAM role/user that the dashboard's AWS SDK uses (Cost Explorer does not support resource-level scoping for this action):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ReadCostExplorerUsage",
      "Effect": "Allow",
      "Action": "ce:GetCostAndUsage",
      "Resource": "*"
    }
  ]
}
```

Prefer the EC2 instance role in production. For local development, use freshly rotated AWS credentials through the AWS SDK credential chain or a named AWS profile; do not commit secrets. `AWS_DEFAULT_REGION` remains the region for EC2 operations, while Cost Explorer requests use its global `us-east-1` API endpoint. After changing server environment variables run `php artisan config:cache`. Verify the identity safely on the server with `aws sts get-caller-identity`, then test the permission with `aws ce get-cost-and-usage --time-period "Start=2026-10-01,End=2026-10-13" --granularity MONTHLY --metrics UnblendedCost --group-by "Type=DIMENSION,Key=REGION" --region us-east-1` (replace the dates with a valid range for your account). Never paste credential values into tickets or chat.

The dashboard does not integrate a CVE vulnerability scanner. WordPress outdated plugin/core reports and AWS security-patch counts are update findings, not confirmed exploitable vulnerabilities; any resolved count is inferred from a later report or a reduced patch count, not verified installation evidence. Backup rows are the stated policy only (weekly EC2, daily WP Engine); verify provider jobs monthly and record a quarterly restore test in Support Ticketing.

Ticket creation and every edit now record the actor and changed fields. Historical changes made before this migration cannot be reconstructed. Keep the Laravel scheduler running every minute in production for both five-minute uptime probes and monthly delivery. The manager's report URL is generated from `APP_URL`; configure it to the canonical public HTTPS dashboard address.

### Scheduled Tasks (Frontend Page Checks and Uptime)

Registered webpages under **Frontend Page Checks** are re-checked automatically every hour via the `webpage-checks:run` command; uptime checks run every five minutes via `uptime:check`; the monthly report sends on the 5th via `reports:send-monthly`. All use Laravel's scheduler (`app/Console/Kernel.php`). The scheduler itself needs something invoking it every minute:

- **Local development:** run `php artisan schedule:work` in a terminal and leave it running; it loops in the foreground and fires due tasks (no OS cron needed).
- **Production (Linux):** add a single cron entry instead:
  ```
  * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
  ```

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
5. Select **Send plugin report now** to confirm the connection. The plugin then posts a report every six hours through WordPress cron.
6. Future reporter releases show up as a normal update on the WordPress **Plugins** page (via `pre_set_site_transient_update_plugins`/`plugins_api`) once step 4 is saved — use **Update now** there instead of re-uploading the ZIP again.

The reporter uses WordPress's native plugin and core update checks. It sends the installed version, newest available version, and whether an update is available for every installed plugin, plus the same information for WordPress core, plus the site's running PHP version and whether that PHP version is still supported (via WordPress's own `wp_check_php_version()` check), plus a front-end visit count since the last report (used to plot the dashboard's Website Traffic Trend chart for sites that are also registered under Frontend Page Checks). It also hooks into `wp_login` to immediately report the username and client IP address of every wp-admin login to the dashboard.

## Reporter Security

The included reporter ZIP is version 1.8.0. It requires an HTTPS endpoint by default and will not submit reports to plain HTTP URLs in production. During development, an administrator can explicitly enable **Development HTTP endpoint** in the WordPress reporter settings, save, and use an `http://` endpoint temporarily. This setting only takes effect while the site's `wp_get_environment_type()` reports `development` (set `WP_ENVIRONMENT_TYPE` in `wp-config.php` or as an env var) — it is ignored on staging/production even if left checked, so it can't accidentally downgrade a live site to unencrypted transport. Disable that option before production anyway because HTTP does not protect report metadata in transit.

Enabling this option only removes the reporter's HTTPS validation. It does not make `127.0.0.1` reachable from another machine: a public WordPress site still needs an address that routes to the dashboard development machine, such as a LAN hostname, a temporary HTTPS tunnel, or the final public deployment domain. It provides the following protections:

- The site token is encrypted before it is stored in WordPress and is never sent in an HTTP header or request body.
- Each report contains an HMAC-SHA256 signature over the exact JSON payload, a cryptographically random nonce, and a timestamp.
- The dashboard accepts reports only within five minutes and records every nonce for ten minutes afterward, preventing replay of a captured request without accumulating unbounded transient entries.
- HTTPS certificate validation is enforced, redirects are disabled, unsafe URLs are rejected, and the dashboard response is size-limited.
- The configured endpoint's host is resolved and rejected if it points at a private (RFC1918), loopback, link-local, or other reserved IP range (this also blocks cloud metadata addresses like `169.254.169.254`), unless Development HTTP mode is active.
- The dashboard-initiated `wp-json/serverdashboard/v1/trigger-report` REST endpoint rate-limits requests (one per 30 seconds) after signature verification, in addition to its existing HMAC authentication, timestamp window, and nonce replay protection.
- Self-update manifests are signed (RSA-SHA256) by the dashboard and verified against a public key embedded in the plugin before the advertised download URL is ever trusted, so a compromised dashboard host can't push an unauthorized plugin "update".
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

For live data, set `AWS_SYNC_USE_MOCK=false`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and `AWS_DEFAULT_REGION` in `.env`. The AWS identity needs `ec2:DescribeInstances`, `ssm:DescribeInstancePatchStates`, `ssm:DescribeInstanceInformation`, and `ssm:SendCommand` permissions (the last one is required because, in addition to reading patch compliance, **Sync EC2 updates** now also triggers `AWS-RunPatchBaseline` with `Operation=Install` on every discovered instance — this installs pending OS patches immediately and can cause a reboot). Clicking the button also asks every active WordPress site's reporter plugin to send an immediate report instead of waiting for its next 6-hourly cron run. Sign in as an admin and select **Sync EC2 updates**.

`AWS_SYNC_USE_MOCK=true` enables the safe development fallback shown by the seeded dashboard (no real AWS calls are made, and no patch install is triggered).
