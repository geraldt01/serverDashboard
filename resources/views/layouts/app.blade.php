<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('images/fav.jpg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --shell-max:1640px;
            --bg:#f6f5fb; --panel:#ffffff; --line:#e9e5f2; --ink:#241d33; --muted:#736c88;
            --red:#e2173d; --magenta:#c81e78; --purple:#7b2ff7;
            --gradient:linear-gradient(135deg,var(--red),var(--magenta) 55%,var(--purple));
            --ok:#0f9d58; --ok-bg:#e3f7ea; --warn:#b06a00; --warn-bg:#fff1de; --danger:#c81e3a; --danger-bg:#fde7ea;
        }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; color:var(--ink); font-family:Inter,"Segoe UI",Tahoma,sans-serif; background:var(--bg); }
        a { color:var(--purple); }

        .site-header { position:sticky; top:0; z-index:50; background:#fff; border-bottom:1px solid var(--line); padding:10px 24px; }
        .site-header-inner { max-width:var(--shell-max); margin:auto; display:flex; align-items:center; }
        .brand { display:flex; align-items:center; gap:12px; }
        .brand-logo { height:32px; width:auto; display:block; }
        .brand-tag { color:var(--muted); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.12em; padding-left:12px; margin-left:2px; border-left:1px solid var(--line); }

        .shell { max-width:var(--shell-max); margin:auto; padding:24px; }
        .panel { background:var(--panel); border:1px solid var(--line); border-radius:14px; box-shadow:0 10px 26px rgba(36,29,51,.06); }
        .topbar { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:18px 22px; }
        h1,h2,h3,p { margin-top:0; }
        h1 { font-size:23px; margin-bottom:4px; }
        h2 { font-size:16px; }
        h3 { color:var(--muted); font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; margin-bottom:8px; }
        .muted { color:var(--muted); margin-bottom:0; }
        .actions { display:flex; gap:8px; flex-wrap:wrap; }
        button { appearance:none; border:0; border-radius:8px; padding:10px 15px; background:var(--gradient); color:#fff; font-weight:700; font-size:13.5px; cursor:pointer; box-shadow:0 8px 18px rgba(200,30,120,.22); }
        button:hover { filter:brightness(1.06); }
        button.secondary { background:#fff; color:var(--ink); border:2px solid #d91a53; box-shadow:none; }
        button.secondary:hover { background:#f4f1fb; filter:none; }
        .meters { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:14px; margin:16px 0; }
        .meter { padding:16px; }
        .value { font-size:30px; font-weight:800; }
        .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
        .wide { grid-column:1 / -1; }
        .content { padding:20px; }
        .content h2 { display:flex; align-items:center; gap:8px; }
        .content h2::before { content:""; width:6px; height:6px; border-radius:2px; background:var(--gradient); flex:none; }
        .chart-area { height:320px; }
        table { width:100%; border-collapse:collapse; }
        th,td { padding:10px 8px; text-align:left; border-bottom:1px solid var(--line); font-size:14px; vertical-align:top; }
        th { color:var(--muted); font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:.03em; }
        .scroll { overflow-x:auto; }
        .badge { display:inline-block; padding:4px 9px; border-radius:999px; font-size:12px; font-weight:700; }
        .ok { color:var(--ok); background:var(--ok-bg); }
        .warning { color:var(--warn); background:var(--warn-bg); }
        .danger { color:var(--danger); background:var(--danger-bg); }
        .pagination { display:flex; align-items:center; gap:10px; margin-top:10px; }
        .pagination button { padding:6px 10px; font-size:13px; }
        .pagination button:disabled { opacity:.5; cursor:not-allowed; }
        .notice { padding:12px 14px; margin:16px 0; color:var(--ok); background:var(--ok-bg); border:1px solid #bfe9cf; border-radius:8px; }
        .form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
        label { display:block; font-size:13px; font-weight:700; color:var(--muted); margin-bottom:4px; }
        input,select,textarea { width:100%; padding:9px 10px; border:1px solid var(--line); border-radius:8px; font-size:14px; font-family:inherit; background:#fff; color:var(--ink); }
        input:focus,select:focus,textarea:focus { outline:2px solid var(--purple); outline-offset:1px; }
        .error { display:block; color:var(--danger); font-size:12px; margin-top:3px; }
        code { background:#f4f1fb; padding:2px 5px; border-radius:4px; font-size:13px; }
        @media(max-width:800px){ .shell{padding:14px}.topbar{align-items:flex-start;flex-direction:column}.meters,.grid{grid-template-columns:1fr}.wide{grid-column:auto}.form-grid{grid-template-columns:1fr;} }

        .admin-sidebar { position:fixed; top:90px; left:max(24px,calc((100vw - var(--shell-max)) / 2)); width:196px; padding:16px 12px; }
        .admin-sidebar h2 { margin:0 0 10px; color:var(--muted); font-size:11px; letter-spacing:.08em; text-transform:uppercase; }
        .admin-sidebar nav { display:grid; gap:3px; }
        .admin-sidebar a { display:flex; align-items:center; gap:9px; padding:10px 12px; color:var(--muted); border-radius:8px; font-size:13.5px; font-weight:600; text-decoration:none; }
        .admin-sidebar a .ic { width:14px; text-align:center; opacity:.85; }
        .admin-sidebar a:hover,.admin-sidebar a:focus { color:var(--ink); background:#f4f1fb; }
        .admin-sidebar a.is-active { color:#fff; background:var(--gradient); box-shadow:0 6px 16px rgba(200,30,120,.24); }
        .shell:has(.admin-sidebar) { max-width:calc(var(--shell-max) - 210px); margin-right:max(24px,calc((100vw - var(--shell-max)) / 2)); margin-left:calc(max(24px,calc((100vw - var(--shell-max)) / 2)) + 216px); }
        @media (max-width:900px) { .admin-sidebar { position:static; width:auto; margin-bottom:16px; } .admin-sidebar nav { grid-template-columns:repeat(3,minmax(0,1fr)); } .shell:has(.admin-sidebar) { max-width:var(--shell-max); margin:auto; } }
        @media (max-width:640px) { .shell { padding:14px; } .topbar { align-items:flex-start; flex-direction:column; } .meters,.grid,.form-grid { grid-template-columns:1fr; } .admin-sidebar nav { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="site-header-inner">
            <div class="brand"><img src="{{ asset('images/nexgen-logo.png') }}" alt="Nexgen Australia" class="brand-logo"><span class="brand-tag">Dashboard</span></div>
        </div>
    </header>
    <main class="shell">@yield('content')</main>
</body>
</html>