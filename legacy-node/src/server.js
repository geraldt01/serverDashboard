import "dotenv/config";
import express from "express";
import helmet from "helmet";
import cors from "cors";
import cookieParser from "cookie-parser";
import rateLimit from "express-rate-limit";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { initDb, dbHelpers } from "./db.js";
import {
  authRequired,
  clearAuthCookie,
  comparePassword,
  hashPassword,
  requireRole,
  setAuthCookie,
  signToken
} from "./auth.js";
import { syncEc2Status } from "./monitoring.js";

const app = express();
const PORT = Number(process.env.PORT || 4000);
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

app.use(
  helmet({
    contentSecurityPolicy: false
  })
);

app.use(
  cors({
    origin: process.env.CORS_ORIGIN || "http://localhost:4000",
    credentials: true
  })
);

app.use(express.json({ limit: "200kb" }));
app.use(cookieParser());

app.use(
  rateLimit({
    windowMs: 15 * 60 * 1000,
    max: 500,
    standardHeaders: true,
    legacyHeaders: false
  })
);

const loginLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 25,
  standardHeaders: true,
  legacyHeaders: false
});

function validateEmail(email) {
  return typeof email === "string" && email.length <= 255 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function validatePassword(password) {
  return typeof password === "string" && password.length >= 8 && password.length <= 128;
}

function verifyIngestKey(req, res, next) {
  const expected = process.env.MONITOR_INGEST_KEY;
  if (!expected) {
    return res.status(500).json({ error: "MONITOR_INGEST_KEY is not configured." });
  }

  const provided = req.headers["x-monitor-key"];
  if (!provided || provided !== expected) {
    return res.status(401).json({ error: "Invalid ingest key." });
  }

  return next();
}

app.post("/api/auth/login", loginLimiter, async (req, res) => {
  const { email, password } = req.body || {};

  if (!validateEmail(email) || !validatePassword(password)) {
    return res.status(400).json({ error: "Invalid login payload." });
  }

  const user = await dbHelpers.get("SELECT id, email, password_hash, role FROM users WHERE email = ?", [email]);
  if (!user) {
    return res.status(401).json({ error: "Invalid credentials." });
  }

  const passwordOk = await comparePassword(password, user.password_hash);
  if (!passwordOk) {
    return res.status(401).json({ error: "Invalid credentials." });
  }

  const token = signToken({ id: user.id, email: user.email, role: user.role });
  setAuthCookie(res, token);

  return res.json({
    message: "Login successful.",
    user: { id: user.id, email: user.email, role: user.role }
  });
});

app.post("/api/auth/logout", authRequired, (req, res) => {
  clearAuthCookie(res);
  return res.json({ message: "Logged out." });
});

app.get("/api/auth/me", authRequired, (req, res) => {
  return res.json({ user: req.user });
});

app.get("/api/users", authRequired, requireRole("admin"), async (_req, res) => {
  const rows = await dbHelpers.all(
    "SELECT id, email, role, created_at FROM users ORDER BY created_at DESC"
  );
  return res.json({ rows });
});

app.post("/api/users", authRequired, requireRole("admin"), async (req, res) => {
  const { email, password, role } = req.body || {};

  if (!validateEmail(email) || !validatePassword(password)) {
    return res.status(400).json({ error: "Invalid user payload." });
  }

  if (!["admin", "viewer"].includes(role)) {
    return res.status(400).json({ error: "role must be admin or viewer." });
  }

  const exists = await dbHelpers.get("SELECT id FROM users WHERE email = ?", [email]);
  if (exists) {
    return res.status(409).json({ error: "Email already exists." });
  }

  const passwordHash = await hashPassword(password);
  await dbHelpers.run(
    "INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)",
    [email, passwordHash, role]
  );

  return res.status(201).json({ message: "User created." });
});

app.post("/api/traffic/ingest", verifyIngestKey, async (req, res) => {
  const { siteName, visits, recordedAt } = req.body || {};
  if (typeof siteName !== "string" || siteName.length < 2 || siteName.length > 120) {
    return res.status(400).json({ error: "Invalid siteName." });
  }

  const parsedVisits = Number(visits);
  if (!Number.isInteger(parsedVisits) || parsedVisits < 0 || parsedVisits > 10000000) {
    return res.status(400).json({ error: "Invalid visits." });
  }

  if (recordedAt && Number.isNaN(Date.parse(recordedAt))) {
    return res.status(400).json({ error: "Invalid recordedAt." });
  }

  await dbHelpers.run(
    `INSERT INTO traffic_events (site_name, visits, recorded_at)
     VALUES (?, ?, COALESCE(?, datetime('now')))`,
    [siteName, parsedVisits, recordedAt || null]
  );

  return res.json({ message: "Traffic event saved." });
});

app.post("/api/wordpress/ingest", verifyIngestKey, async (req, res) => {
  const { siteName, plugins } = req.body || {};
  if (typeof siteName !== "string" || siteName.length < 2 || siteName.length > 120) {
    return res.status(400).json({ error: "Invalid siteName." });
  }

  if (!Array.isArray(plugins) || !plugins.length) {
    return res.status(400).json({ error: "plugins must be a non-empty array." });
  }

  let inserted = 0;
  for (const p of plugins) {
    const pluginName = String(p.pluginName || "").trim();
    const currentVersion = String(p.currentVersion || "").trim();
    const latestVersion = String(p.latestVersion || "").trim();
    const status = ["up_to_date", "outdated", "unknown"].includes(p.status) ? p.status : "unknown";

    if (!pluginName || !currentVersion || !latestVersion) {
      continue;
    }

    await dbHelpers.run(
      `INSERT INTO wp_plugin_updates (site_name, plugin_name, current_version, latest_version, status)
       VALUES (?, ?, ?, ?, ?)`,
      [siteName, pluginName, currentVersion, latestVersion, status]
    );
    inserted += 1;
  }

  return res.json({ message: "Plugin updates saved.", inserted });
});

app.post("/api/monitor/sync/ec2", authRequired, requireRole("admin"), async (_req, res) => {
  const result = await syncEc2Status();
  return res.json({ message: "EC2 status sync complete.", ...result });
});

app.get("/api/dashboard/summary", authRequired, async (_req, res) => {
  const trafficTotal = await dbHelpers.get(
    "SELECT COALESCE(SUM(visits), 0) as total FROM traffic_events WHERE recorded_at >= datetime('now', '-24 hour')"
  );
  const outdatedPlugins = await dbHelpers.get(
    "SELECT COUNT(*) as total FROM wp_plugin_updates WHERE status = 'outdated'"
  );
  const ec2Missing = await dbHelpers.get(
    "SELECT COALESCE(SUM(missing_count), 0) as total FROM ec2_patch_status WHERE checked_at >= datetime('now', '-24 hour')"
  );

  return res.json({
    trafficLast24h: trafficTotal?.total || 0,
    outdatedPlugins: outdatedPlugins?.total || 0,
    ec2MissingPatches: ec2Missing?.total || 0
  });
});

app.get("/api/traffic", authRequired, async (req, res) => {
  const days = Math.min(Math.max(Number(req.query.days) || 14, 1), 60);
  const rows = await dbHelpers.all(
    `SELECT date(recorded_at) as day, site_name, SUM(visits) as visits
     FROM traffic_events
     WHERE recorded_at >= datetime('now', ?)
     GROUP BY date(recorded_at), site_name
     ORDER BY day ASC`,
    [`-${days} day`]
  );

  return res.json({ rows });
});

app.get("/api/wordpress/plugins/latest", authRequired, async (_req, res) => {
  const rows = await dbHelpers.all(
    `SELECT w1.site_name, w1.plugin_name, w1.current_version, w1.latest_version, w1.status, w1.checked_at
     FROM wp_plugin_updates w1
     INNER JOIN (
       SELECT site_name, plugin_name, MAX(id) AS latest_id
       FROM wp_plugin_updates
       GROUP BY site_name, plugin_name
     ) w2 ON w1.id = w2.latest_id
     ORDER BY w1.status DESC, w1.site_name ASC`
  );

  return res.json({ rows });
});

app.get("/api/ec2/latest", authRequired, async (_req, res) => {
  const rows = await dbHelpers.all(
    `SELECT e1.instance_id, e1.instance_name, e1.missing_count, e1.installed_count, e1.failed_count, e1.reboot_required, e1.checked_at
     FROM ec2_patch_status e1
     INNER JOIN (
       SELECT instance_id, MAX(id) AS latest_id
       FROM ec2_patch_status
       GROUP BY instance_id
     ) e2 ON e1.id = e2.latest_id
     ORDER BY e1.missing_count DESC, e1.instance_name ASC`
  );

  return res.json({ rows });
});

app.use(express.static(path.resolve(__dirname, "../public")));

app.get("*", (_req, res) => {
  res.sendFile(path.resolve(__dirname, "../public/index.html"));
});

async function start() {
  await initDb();
  app.listen(PORT, () => {
    console.log(`ServerDashboard running on http://localhost:${PORT}`);
  });
}

start().catch((err) => {
  console.error("Startup failure:", err);
  process.exit(1);
});
