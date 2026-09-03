# ServerDashboard

A secure dashboard system to monitor:
- WordPress plugin update status
- AWS EC2 update/patch status
- Website traffic charts/meters

## Features
- User login with roles (`admin`, `viewer`)
- JWT authentication and role-based access control
- Security hardening (Helmet, rate limiting, input validation, secure password hashing)
- Traffic charts and summary meters
- Monitoring APIs for WordPress plugin and EC2 update status

## Quick Start
1. Copy `.env.example` to `.env` and set values.
2. Install dependencies:
   - `npm install`
3. Seed default admin user:
   - `npm run seed`
4. Run app:
   - `npm run dev`
5. Open:
   - `http://localhost:4000`

## API Overview
- `POST /api/auth/login` for login
- `POST /api/auth/logout` for logout
- `GET /api/auth/me` current user session
- `GET /api/users` admin-only list users
- `POST /api/users` admin-only create users/roles
- `GET /api/dashboard/summary` meters data
- `GET /api/traffic?days=14` traffic chart data
- `GET /api/wordpress/plugins/latest` latest plugin update status
- `GET /api/ec2/latest` latest EC2 patch status
- `POST /api/monitor/sync/ec2` admin-only AWS EC2 patch sync
- `POST /api/traffic/ingest` secure traffic ingest from websites
- `POST /api/wordpress/ingest` secure WordPress plugin status ingest

## WordPress Plugin Monitoring Ingest
Set `MONITOR_INGEST_KEY` in `.env`, then push plugin status from WordPress or another scheduler:

```bash
curl -X POST http://localhost:4000/api/wordpress/ingest \
   -H "Content-Type: application/json" \
   -H "x-monitor-key: YOUR_INGEST_KEY" \
   -d '{
      "siteName": "wordpress-main",
      "plugins": [
         {
            "pluginName": "elementor/elementor.php",
            "currentVersion": "3.21.0",
            "latestVersion": "3.24.1",
            "status": "outdated"
         }
      ]
   }'
```

## Website Traffic Ingest
Send traffic metrics from each site periodically:

```bash
curl -X POST http://localhost:4000/api/traffic/ingest \
   -H "Content-Type: application/json" \
   -H "x-monitor-key: YOUR_INGEST_KEY" \
   -d '{
      "siteName": "wordpress-main",
      "visits": 842,
      "recordedAt": "2026-08-14T03:00:00Z"
   }'
```

## AWS EC2 Update Monitoring
- Configure `AWS_REGION`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`.
- Ensure the AWS identity can call:
   - `ec2:DescribeInstances`
   - `ssm:DescribeInstancePatchStates`
- Click `Sync EC2 Updates` in the UI as an `admin` user.
- If AWS is unavailable and `ENABLE_MOCK_MONITORING=true`, mock data is used.

## Default Seed User
- Email: `admin@example.com`
- Password: `Admin#12345`
- Role: `admin`

Change this password immediately after first login.
