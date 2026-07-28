<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Agent Workflows')</title>
    <style>
        :root {
            --bg: #0d1117;
            --panel: #151b23;
            --panel-2: #1c232e;
            --border: #2a3340;
            --text: #e6edf3;
            --muted: #93a1b3;
            --faint: #6b7787;
            --accent: #ff6d5a;
            --green: #3fb950;
            --blue: #58a6ff;
            --red: #f85149;
            --amber: #d29922;
            --mono: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        a { color: var(--blue); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .topbar {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--panel);
            position: sticky; top: 0; z-index: 10;
        }
        .topbar .brand { font-weight: 700; letter-spacing: .2px; color: var(--text); }
        .topbar .brand b { color: var(--accent); }
        .topbar .spacer { flex: 1; }

        .chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 2px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 600;
            border: 1px solid var(--border); color: var(--muted); background: var(--panel-2);
            white-space: nowrap;
        }
        .chip::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: var(--faint); }
        .chip.completed { color: var(--green); border-color: color-mix(in srgb, var(--green) 40%, transparent); }
        .chip.completed::before { background: var(--green); }
        .chip.running, .chip.pending { color: var(--blue); border-color: color-mix(in srgb, var(--blue) 40%, transparent); }
        .chip.running::before, .chip.pending::before { background: var(--blue); animation: pulse 1.2s infinite; }
        .chip.failed { color: var(--red); border-color: color-mix(in srgb, var(--red) 40%, transparent); }
        .chip.failed::before { background: var(--red); }
        .chip.awaiting_human, .chip.awaiting_event { color: var(--amber); border-color: color-mix(in srgb, var(--amber) 45%, transparent); }
        .chip.awaiting_human::before, .chip.awaiting_event::before { background: var(--amber); animation: pulse 1.6s infinite; }
        .chip.cancelled { color: var(--faint); }
        @keyframes pulse { 50% { opacity: .25; } }

        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 7px 14px; border-radius: 8px; cursor: pointer;
            border: 1px solid var(--border); background: var(--panel-2); color: var(--text);
            font-size: 13px; font-weight: 600;
        }
        .btn:hover { border-color: var(--faint); }
        .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn.primary:hover { filter: brightness(1.08); }
        .btn.good { background: var(--green); border-color: var(--green); color: #04260c; }
        .btn.bad { background: transparent; border-color: color-mix(in srgb, var(--red) 55%, transparent); color: var(--red); }
        .btn.subtle { background: transparent; }

        .mono { font-family: var(--mono); font-size: 12.5px; }
        .muted { color: var(--muted); }
        .faint { color: var(--faint); }

        .banner {
            margin: 14px 20px 0; padding: 10px 14px; border-radius: 8px;
            border: 1px solid color-mix(in srgb, var(--red) 45%, transparent);
            background: color-mix(in srgb, var(--red) 12%, var(--panel));
            color: #ffb4ad; font-size: 13px;
        }
    </style>
    @yield('style')
</head>
<body>
<div class="topbar">
    <a class="brand" href="{{ route('agent-workflows.index') }}" style="text-decoration:none;">agent-workflows <b>/ dashboard</b></a>
    @yield('topbar')
</div>

@if ($errors->any())
    <div class="banner">{{ implode(' ', $errors->all()) }}</div>
@endif

@yield('content')

<script>
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;

    function timeAgo(iso) {
        if (!iso) return '—';
        const s = Math.max(0, (Date.now() - new Date(iso)) / 1000);
        if (s < 60) return Math.floor(s) + 's ago';
        if (s < 3600) return Math.floor(s / 60) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        return Math.floor(s / 86400) + 'd ago';
    }

    function duration(a, b) {
        if (!a) return '—';
        const ms = new Date(b || Date.now()) - new Date(a);
        if (ms < 1000) return ms + 'ms';
        if (ms < 60000) return (ms / 1000).toFixed(1) + 's';
        const m = Math.floor(ms / 60000);
        return m + 'm ' + Math.floor((ms % 60000) / 1000) + 's';
    }

    function statusLabel(s) {
        return { awaiting_human: 'awaiting human', awaiting_event: 'awaiting event' }[s] || s;
    }
</script>
@yield('script')
</body>
</html>
