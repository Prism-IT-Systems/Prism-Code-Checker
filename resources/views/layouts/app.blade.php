<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Prism Code Checker')</title>
    <style>
        :root {
            --bg: #f4f7f5;
            --bg-accent: #e7eef0;
            --surface: #ffffff;
            --ink: #14231c;
            --muted: #5b6b63;
            --line: #d5ddd8;
            --brand: #0f6b4c;
            --brand-dark: #0a4d37;
            --critical: #9b1c1c;
            --error: #c2410c;
            --warning: #a16207;
            --notice: #1d4ed8;
            --info: #475569;
            --ok: #166534;
            --shadow: 0 10px 30px rgba(20, 35, 28, 0.06);
            --font-display: "Segoe UI", "Trebuchet MS", sans-serif;
            --font-body: "Segoe UI", "Helvetica Neue", sans-serif;
            --font-mono: "Cascadia Code", "Consolas", "Courier New", monospace;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: var(--font-body);
            color: var(--ink);
            background:
                radial-gradient(circle at top left, #d9ebe3 0, transparent 28%),
                linear-gradient(180deg, var(--bg) 0%, var(--bg-accent) 100%);
            min-height: 100vh;
        }
        a { color: var(--brand); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .shell { max-width: 1100px; margin: 0 auto; padding: 28px 20px 60px; }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 28px;
        }
        .brand {
            font-family: var(--font-display);
            font-size: 1.55rem;
            font-weight: 700;
            letter-spacing: -0.03em;
            color: var(--brand-dark);
        }
        .brand span { color: var(--brand); }
        .nav { display: flex; gap: 16px; color: var(--muted); font-size: 0.95rem; }
        .panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: var(--shadow);
            padding: 24px;
            margin-bottom: 20px;
        }
        h1, h2, h3 { margin: 0 0 12px; letter-spacing: -0.02em; }
        h1 { font-size: 1.8rem; }
        h2 { font-size: 1.2rem; }
        p { color: var(--muted); line-height: 1.5; }
        label { display: block; font-weight: 600; margin-bottom: 8px; }
        input[type="text"], select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 10px;
            font: inherit;
            background: #fbfcfb;
        }
        .row { display: flex; gap: 12px; flex-wrap: wrap; }
        .row > * { flex: 1 1 220px; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
        .btn {
            appearance: none;
            border: 0;
            border-radius: 10px;
            padding: 11px 16px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            background: var(--brand);
            color: white;
        }
        .btn:hover { background: var(--brand-dark); }
        .btn.secondary {
            background: #eef4f1;
            color: var(--brand-dark);
            border: 1px solid var(--line);
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }
        .meta-item {
            background: #f7faf8;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
        }
        .meta-item .label { color: var(--muted); font-size: 0.82rem; margin-bottom: 4px; }
        .meta-item .value { font-weight: 700; word-break: break-word; }
        .counts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin: 18px 0;
        }
        .count {
            border-radius: 12px;
            padding: 14px;
            border: 1px solid var(--line);
            background: #fff;
        }
        .count .n { font-size: 1.6rem; font-weight: 750; }
        .count.critical .n { color: var(--critical); }
        .count.error .n { color: var(--error); }
        .count.warning .n { color: var(--warning); }
        .count.notice .n { color: var(--notice); }
        .status-pill {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: 0.04em;
            font-size: 0.85rem;
        }
        .status-pill.ready { background: #dcfce7; color: var(--ok); }
        .status-pill.fix { background: #fee2e2; color: var(--critical); }
        .filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
        .filters a, .filters span {
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--muted);
            font-size: 0.9rem;
        }
        .filters a.active, .filters span.active {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
        }
        .file-group {
            border: 1px solid var(--line);
            border-radius: 12px;
            margin-bottom: 12px;
            overflow: hidden;
            background: #fff;
        }
        .file-group summary {
            cursor: pointer;
            padding: 14px 16px;
            font-family: var(--font-mono);
            font-weight: 600;
            background: #f8faf9;
            list-style: none;
        }
        .file-group summary::-webkit-details-marker { display: none; }
        .issue {
            padding: 14px 16px;
            border-top: 1px solid var(--line);
        }
        .issue-top {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 6px;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .badge.critical { background: #fee2e2; color: var(--critical); }
        .badge.error { background: #ffedd5; color: var(--error); }
        .badge.warning { background: #fef3c7; color: var(--warning); }
        .badge.notice { background: #dbeafe; color: var(--notice); }
        .badge.info { background: #e2e8f0; color: var(--info); }
        .badge.formatting { background: #f1f5f9; color: #475569; }
        .badge.cat-security { background: #fee2e2; color: var(--critical); }
        .badge.cat-bug { background: #ffedd5; color: var(--error); }
        .badge.cat-practice { background: #fef3c7; color: var(--warning); }
        .badge.cat-style { background: #f1f5f9; color: #475569; }
        .filters .count { opacity: 0.75; font-weight: 600; }
        .legend { color: var(--muted); font-size: 0.9rem; margin: 0 0 16px; }
        .legend strong { color: var(--ink); }
        .location {
            font-family: var(--font-mono);
            font-size: 0.9rem;
            color: var(--brand-dark);
            font-weight: 600;
        }
        .message { margin: 6px 0; }
        .rule { color: var(--muted); font-size: 0.88rem; }
        .errors {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 16px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-size: 0.85rem; font-weight: 600; }
        .empty { color: var(--muted); padding: 18px 0; }
        .summaries { display: grid; gap: 8px; margin-top: 12px; }
        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #f8faf9;
        }
        .pager {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .pager .btn { text-decoration: none; }
        .pager .btn[aria-disabled="true"] {
            opacity: 0.45;
            pointer-events: none;
        }
        @media (max-width: 700px) {
            .topbar { flex-direction: column; align-items: flex-start; }
            .shell { padding: 18px 14px 40px; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="topbar">
            <div class="brand">Prism <span>Code Checker</span></div>
            <div class="nav">
                <a href="{{ route('dashboard') }}">Dashboard</a>
                <a href="{{ route('scans.index') }}">Scan History</a>
            </div>
        </div>

        @if ($errors->any())
            <div class="errors">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @yield('content')
    </div>
</body>
</html>
