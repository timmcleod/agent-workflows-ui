@extends('agent-workflows-ui::layout')

@section('title', 'Workflow Runs')

@section('style')
<style>
    .wrap { max-width: 1060px; margin: 26px auto; padding: 0 20px; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    .sub { color: var(--muted); margin-bottom: 18px; font-size: 13px; }

    table.runs { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
    .runs th, .runs td { text-align: left; padding: 10px 14px; border-bottom: 1px solid var(--border); font-size: 13px; }
    .runs th { color: var(--faint); font-size: 11px; text-transform: uppercase; letter-spacing: .6px; background: var(--panel-2); }
    .runs tr:last-child td { border-bottom: 0; }
    .runs tbody tr { cursor: pointer; }
    .runs tbody tr:hover { background: var(--panel-2); }
    .empty { padding: 48px; text-align: center; color: var(--faint); background: var(--panel); border: 1px dashed var(--border); border-radius: 10px; }
</style>
@endsection

@section('content')
<div class="wrap">
    <h1>Runs</h1>
    <div class="sub">Every workflow run, live.</div>
    <div id="list"></div>
</div>
@endsection

@section('script')
<script>
    const listEl = document.getElementById('list');

    function render(runs) {
        if (!runs.length) {
            listEl.innerHTML = '<div class="empty">No runs yet — start one with <span class="mono">AgentWorkflow::start(...)</span>.</div>';
            return;
        }

        const rows = runs.map(r => `
            <tr onclick="location.href='${RUNS_BASE}/${r.id}'">
                <td class="mono">${r.id.slice(-8)}</td>
                <td>${r.name}</td>
                <td><span class="chip ${r.status}" ${r.stalled ? 'title="Queued, but no worker has claimed the next step — is a queue worker running?"' : ''}>${r.stalled ? 'queued — no worker?' : statusLabel(r.status)}</span></td>
                <td class="mono muted">${r.failed_step ?? r.current_step ?? '—'}</td>
                <td class="muted">${r.steps_count}</td>
                <td class="muted">${timeAgo(r.created_at)}</td>
                <td class="muted">${r.status === 'running' ? duration(r.started_at) : (r.finished_at ? duration(r.started_at, r.finished_at) : '—')}</td>
            </tr>`).join('');

        listEl.innerHTML = `
            <table class="runs">
                <thead><tr>
                    <th>Run</th><th>Workflow</th><th>Status</th><th>At step</th>
                    <th>Steps</th><th>Started</th><th>Duration</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
    }

    const RUNS_BASE = '{{ route('agent-workflows.index') }}/runs';

    async function poll() {
        try {
            const res = await fetch('{{ route('agent-workflows.data') }}', { headers: { Accept: 'application/json' } });
            render((await res.json()).runs);
        } catch (e) { /* transient — keep polling */ }
    }

    poll();
    setInterval(poll, {{ (int) config('agent-workflows-ui.polling', 2500) }});
</script>
@endsection
