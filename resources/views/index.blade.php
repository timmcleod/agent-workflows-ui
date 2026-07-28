@extends('agent-workflows-ui::layout')

@section('title', 'Workflow Runs')

@section('style')
<style>
    .wrap { max-width: 1120px; margin: 26px auto; padding: 0 20px; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    .sub { color: var(--muted); margin-bottom: 14px; font-size: 13px; }

    .filters { display: flex; align-items: center; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; }
    .filters .fchip {
        padding: 4px 12px; border-radius: 999px; cursor: pointer;
        border: 1px solid var(--border); background: var(--panel); color: var(--muted);
        font: inherit; font-size: 12.5px; font-weight: 600;
    }
    .filters .fchip:hover { border-color: var(--faint); }
    .filters .fchip.on { background: var(--panel-2); color: var(--text); border-color: var(--faint); }
    .filters select {
        margin-left: auto; background: var(--panel); color: var(--text);
        border: 1px solid var(--border); border-radius: 8px; padding: 5px 10px;
        font: inherit; font-size: 12.5px;
    }

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
    <div class="filters">
        <button class="fchip on" data-status="">All</button>
        <button class="fchip" data-status="active">Running</button>
        <button class="fchip" data-status="awaiting">Awaiting</button>
        <button class="fchip" data-status="failed">Failed</button>
        <button class="fchip" data-status="completed">Completed</button>
        <button class="fchip" data-status="cancelled">Cancelled</button>
        <select id="workflow-filter" style="display:none;">
            <option value="">All workflows</option>
        </select>
    </div>
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
                <td class="muted">${r.tokens > 0 ? r.tokens.toLocaleString() : '—'}</td>
                <td class="muted">${timeAgo(r.created_at)}</td>
                <td class="muted">${r.status === 'running' ? duration(r.started_at) : (r.finished_at ? duration(r.started_at, r.finished_at) : '—')}</td>
            </tr>`).join('');

        listEl.innerHTML = `
            <table class="runs">
                <thead><tr>
                    <th>Run</th><th>Workflow</th><th>Status</th><th>At step</th>
                    <th>Steps</th><th>Tokens</th><th>Started</th><th>Duration</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
    }

    const RUNS_BASE = '{{ route('agent-workflows.index') }}/runs';

    const filter = { status: '', workflow: '' };
    const workflowSelect = document.getElementById('workflow-filter');

    document.querySelectorAll('.fchip').forEach(chip => chip.addEventListener('click', () => {
        document.querySelectorAll('.fchip').forEach(c => c.classList.toggle('on', c === chip));
        filter.status = chip.dataset.status;
        poll();
    }));

    workflowSelect.addEventListener('change', () => {
        filter.workflow = workflowSelect.value;
        poll();
    });

    function renderWorkflowOptions(names) {
        if (names.length < 2) return;

        const current = workflowSelect.value;
        workflowSelect.style.display = '';
        workflowSelect.innerHTML = '<option value="">All workflows</option>'
            + names.map(n => `<option value="${n}" ${n === current ? 'selected' : ''}>${n}</option>`).join('');
    }

    async function poll() {
        try {
            const params = new URLSearchParams();
            if (filter.status) params.set('status', filter.status);
            if (filter.workflow) params.set('workflow', filter.workflow);

            const res = await fetch('{{ route('agent-workflows.data') }}?' + params, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            render(data.runs);
            renderWorkflowOptions(data.workflows ?? []);
        } catch (e) { /* transient — keep polling */ }
    }

    poll();
    setInterval(poll, {{ (int) config('agent-workflows-ui.polling', 2500) }});
</script>
@endsection
