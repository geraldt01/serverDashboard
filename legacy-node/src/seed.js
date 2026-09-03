import "dotenv/config";
import { initDb, dbHelpers } from "./db.js";
import { hashPassword } from "./auth.js";

async function seed() {
  await initDb();

  const adminEmail = "admin@example.com";
  const existing = await dbHelpers.get("SELECT id FROM users WHERE email = ?", [adminEmail]);

  if (!existing) {
    const passwordHash = await hashPassword("Admin#12345");
    await dbHelpers.run(
      "INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)",
      [adminEmail, passwordHash, "admin"]
    );
  }

  const viewerEmail = "viewer@example.com";
  const viewer = await dbHelpers.get("SELECT id FROM users WHERE email = ?", [viewerEmail]);

  if (!viewer) {
    const passwordHash = await hashPassword("Viewer#12345");
    await dbHelpers.run(
      "INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)",
      [viewerEmail, passwordHash, "viewer"]
    );
  }

  const trafficRows = await dbHelpers.get("SELECT COUNT(*) as count FROM traffic_events");
  if ((trafficRows?.count || 0) === 0) {
    const sites = ["nexus-central-app", "nexgen-configapp", "wordpress-main"];
    for (let i = 13; i >= 0; i -= 1) {
      for (const site of sites) {
        const visits = Math.floor(Math.random() * 700) + 80;
        await dbHelpers.run(
          `INSERT INTO traffic_events (site_name, visits, recorded_at)
           VALUES (?, ?, datetime('now', ?))`,
          [site, visits, `-${i} day`]
        );
      }
    }
  }

  const wpRows = await dbHelpers.get("SELECT COUNT(*) as count FROM wp_plugin_updates");
  if ((wpRows?.count || 0) === 0) {
    const sample = [
      ["wordpress-main", "akismet/akismet.php", "5.3", "5.3", "up_to_date"],
      ["wordpress-main", "elementor/elementor.php", "3.21.0", "3.24.1", "outdated"],
      ["wordpress-main", "wordfence/wordfence.php", "7.11.5", "7.11.5", "up_to_date"]
    ];

    for (const row of sample) {
      await dbHelpers.run(
        `INSERT INTO wp_plugin_updates (site_name, plugin_name, current_version, latest_version, status)
         VALUES (?, ?, ?, ?, ?)`,
        row
      );
    }
  }

  const ec2Rows = await dbHelpers.get("SELECT COUNT(*) as count FROM ec2_patch_status");
  if ((ec2Rows?.count || 0) === 0) {
    await dbHelpers.run(
      `INSERT INTO ec2_patch_status (instance_id, instance_name, missing_count, installed_count, failed_count, reboot_required)
       VALUES (?, ?, ?, ?, ?, ?)`,
      ["i-0a1b2c3d4e5f001", "wp-prod-1", 4, 112, 0, 1]
    );
    await dbHelpers.run(
      `INSERT INTO ec2_patch_status (instance_id, instance_name, missing_count, installed_count, failed_count, reboot_required)
       VALUES (?, ?, ?, ?, ?, ?)`,
      ["i-0a1b2c3d4e5f002", "api-prod-1", 0, 97, 0, 0]
    );
  }

  console.log("Seed complete.");
}

seed().catch((err) => {
  console.error("Seed failed:", err);
  process.exit(1);
});
