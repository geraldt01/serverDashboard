let trafficChart;

const loginSection = document.getElementById("loginSection");
const dashboardSection = document.getElementById("dashboardSection");
const loginForm = document.getElementById("loginForm");
const loginError = document.getElementById("loginError");
const userLabel = document.getElementById("userLabel");
const syncEc2Btn = document.getElementById("syncEc2Btn");
const refreshBtn = document.getElementById("refreshBtn");
const logoutBtn = document.getElementById("logoutBtn");

async function api(path, options = {}) {
  const response = await fetch(path, {
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      ...(options.headers || {})
    },
    ...options
  });

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(data.error || "Request failed");
  }

  return data;
}

function showLogin() {
  loginSection.classList.remove("hidden");
  dashboardSection.classList.add("hidden");
}

function showDashboard() {
  loginSection.classList.add("hidden");
  dashboardSection.classList.remove("hidden");
}

function statusBadge(status) {
  if (status === "up_to_date") {
    return '<span class="badge badge-ok">up_to_date</span>';
  }
  if (status === "outdated") {
    return '<span class="badge badge-warn">outdated</span>';
  }
  return '<span class="badge">unknown</span>';
}

function rebootBadge(value) {
  return value ? '<span class="badge badge-warn">required</span>' : '<span class="badge badge-ok">no</span>';
}

function renderTraffic(rows) {
  const labels = [...new Set(rows.map((r) => r.day))];
  const sites = [...new Set(rows.map((r) => r.site_name))];

  const datasets = sites.map((site, idx) => {
    const color = ["#0f766e", "#b66a00", "#375ab7", "#7e22ce"][idx % 4];
    return {
      label: site,
      data: labels.map((day) => {
        const match = rows.find((r) => r.day === day && r.site_name === site);
        return match ? Number(match.visits) : 0;
      }),
      borderColor: color,
      backgroundColor: color,
      tension: 0.25
    };
  });

  const ctx = document.getElementById("trafficChart");
  if (trafficChart) {
    trafficChart.destroy();
  }

  trafficChart = new Chart(ctx, {
    type: "line",
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: "bottom" }
      }
    }
  });
}

async function loadDashboard() {
  const me = await api("/api/auth/me");
  const summary = await api("/api/dashboard/summary");
  const traffic = await api("/api/traffic?days=14");
  const wp = await api("/api/wordpress/plugins/latest");
  const ec2 = await api("/api/ec2/latest");

  userLabel.textContent = `${me.user.email} (${me.user.role})`;
  document.getElementById("meterTraffic").textContent = summary.trafficLast24h.toLocaleString();
  document.getElementById("meterPlugins").textContent = summary.outdatedPlugins.toLocaleString();
  document.getElementById("meterEc2").textContent = summary.ec2MissingPatches.toLocaleString();

  renderTraffic(traffic.rows);

  const wpBody = document.querySelector("#wpTable tbody");
  wpBody.innerHTML = wp.rows
    .map(
      (row) => `
      <tr>
        <td>${row.site_name}</td>
        <td>${row.plugin_name}</td>
        <td>${row.current_version}</td>
        <td>${row.latest_version}</td>
        <td>${statusBadge(row.status)}</td>
      </tr>
    `
    )
    .join("");

  const ec2Body = document.querySelector("#ec2Table tbody");
  ec2Body.innerHTML = ec2.rows
    .map(
      (row) => `
      <tr>
        <td>${row.instance_name}</td>
        <td>${row.instance_id}</td>
        <td>${row.missing_count}</td>
        <td>${row.installed_count}</td>
        <td>${row.failed_count}</td>
        <td>${rebootBadge(Boolean(row.reboot_required))}</td>
      </tr>
    `
    )
    .join("");

  syncEc2Btn.style.display = me.user.role === "admin" ? "inline-block" : "none";
}

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  loginError.textContent = "";

  const email = document.getElementById("email").value.trim();
  const password = document.getElementById("password").value;

  try {
    await api("/api/auth/login", {
      method: "POST",
      body: JSON.stringify({ email, password })
    });
    showDashboard();
    await loadDashboard();
  } catch (err) {
    loginError.textContent = err.message;
  }
});

logoutBtn.addEventListener("click", async () => {
  await api("/api/auth/logout", { method: "POST" });
  showLogin();
});

refreshBtn.addEventListener("click", async () => {
  await loadDashboard();
});

syncEc2Btn.addEventListener("click", async () => {
  try {
    await api("/api/monitor/sync/ec2", { method: "POST" });
    await loadDashboard();
  } catch (err) {
    alert(`Sync failed: ${err.message}`);
  }
});

(async function init() {
  try {
    await api("/api/auth/me");
    showDashboard();
    await loadDashboard();
  } catch {
    showLogin();
  }
})();
