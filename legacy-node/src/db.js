import fs from "node:fs";
import path from "node:path";
import sqlite3 from "sqlite3";

const dbPath = process.env.DB_PATH || "./data/dashboard.db";
const resolvedPath = path.resolve(dbPath);
const resolvedDir = path.dirname(resolvedPath);

if (!fs.existsSync(resolvedDir)) {
  fs.mkdirSync(resolvedDir, { recursive: true });
}

export const db = new sqlite3.Database(resolvedPath);

function run(sql, params = []) {
  return new Promise((resolve, reject) => {
    db.run(sql, params, function onRun(err) {
      if (err) return reject(err);
      resolve(this);
    });
  });
}

function all(sql, params = []) {
  return new Promise((resolve, reject) => {
    db.all(sql, params, (err, rows) => {
      if (err) return reject(err);
      resolve(rows);
    });
  });
}

function get(sql, params = []) {
  return new Promise((resolve, reject) => {
    db.get(sql, params, (err, row) => {
      if (err) return reject(err);
      resolve(row);
    });
  });
}

export const dbHelpers = { run, all, get };

export async function initDb() {
  await run(`
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      email TEXT NOT NULL UNIQUE,
      password_hash TEXT NOT NULL,
      role TEXT NOT NULL CHECK(role IN ('admin', 'viewer')),
      created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )
  `);

  await run(`
    CREATE TABLE IF NOT EXISTS traffic_events (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      site_name TEXT NOT NULL,
      visits INTEGER NOT NULL DEFAULT 0,
      recorded_at TEXT NOT NULL DEFAULT (datetime('now'))
    )
  `);

  await run(`
    CREATE TABLE IF NOT EXISTS wp_plugin_updates (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      site_name TEXT NOT NULL,
      plugin_name TEXT NOT NULL,
      current_version TEXT NOT NULL,
      latest_version TEXT NOT NULL,
      status TEXT NOT NULL CHECK(status IN ('up_to_date', 'outdated', 'unknown')),
      checked_at TEXT NOT NULL DEFAULT (datetime('now'))
    )
  `);

  await run(`
    CREATE TABLE IF NOT EXISTS ec2_patch_status (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      instance_id TEXT NOT NULL,
      instance_name TEXT NOT NULL,
      missing_count INTEGER NOT NULL DEFAULT 0,
      installed_count INTEGER NOT NULL DEFAULT 0,
      failed_count INTEGER NOT NULL DEFAULT 0,
      reboot_required INTEGER NOT NULL DEFAULT 0,
      checked_at TEXT NOT NULL DEFAULT (datetime('now'))
    )
  `);
}
