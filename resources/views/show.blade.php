@extends('agent-workflows-ui::layout')

@section('title', 'Run '.substr($run->id, -8))

@section('topbar')
    <a href="{{ route('agent-workflows.index') }}" class="muted">← runs</a>
    <span class="mono faint">{{ $run->id }}</span>
    <span id="run-chip" class="chip {{ $run->status->value }}">{{ $run->status->value }}</span>
    <span id="drift-badge" class="chip" style="display:none;" title="The registered definition no longer matches the one this run started with">⚠ definition drift</span>
    <span class="spacer"></span>
    <form id="retry-form" method="POST" action="{{ route('agent-workflows.retry', $run) }}" style="display:none;">
        @csrf
        <button class="btn primary">↺ Retry failed step</button>
    </form>
    <form id="cancel-form" method="POST" action="{{ route('agent-workflows.cancel', $run) }}" style="display:none;"
          onsubmit="return confirm('Cancel this run?');">
        @csrf
        <button class="btn subtle">✕ Cancel run</button>
    </form>
@endsection

@section('style')
<style>
    .layout { display: grid; grid-template-columns: minmax(0, 1fr) 420px; height: calc(100vh - 54px); }

    /* ---- canvas ---- */
    #canvas {
        position: relative; overflow: auto; padding: 40px 20px 80px;
        background-image: radial-gradient(circle, #232b36 1px, transparent 1px);
        background-size: 22px 22px;
    }
    #edges { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
    #graph { position: relative; display: flex; flex-direction: column; align-items: center; gap: 52px; min-width: max-content; margin: 0 auto; }
    .row { display: flex; gap: 56px; justify-content: center; }

    .node {
        position: relative; width: 240px;
        background: var(--panel); border: 1px solid var(--border); border-radius: 12px;
        padding: 12px 14px 10px; transition: border-color .2s, box-shadow .2s, opacity .2s;
    }
    .node .head { display: flex; gap: 10px; align-items: flex-start; }
    .node .icon {
        flex: none; width: 34px; height: 34px; border-radius: 9px;
        display: flex; align-items: center; justify-content: center; font-size: 16px;
        background: var(--panel-2); border: 1px solid var(--border);
    }
    .node .label { font-weight: 650; font-size: 13px; line-height: 1.25; word-break: break-word; }
    .node .kind { font-size: 10.5px; text-transform: uppercase; letter-spacing: .7px; color: var(--faint); margin-top: 1px; }
    .node .detail { margin-top: 8px; font-size: 12px; color: var(--muted); min-height: 16px; }
    .node .foot { margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 8px; font-size: 11.5px; color: var(--faint); min-height: 18px; }
    .node .foot .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--faint); flex: none; }

    .node.completed { border-color: color-mix(in srgb, var(--green) 55%, transparent); }
    .node.completed .dot { background: var(--green); }
    .node.running { border-color: var(--blue); box-shadow: 0 0 0 3px color-mix(in srgb, var(--blue) 22%, transparent); }
    .node.running .dot { background: var(--blue); animation: pulse 1s infinite; }
    .node.queued { border-style: dashed; border-color: color-mix(in srgb, var(--blue) 60%, transparent); }
    .node.queued .dot { background: var(--blue); }
    .node.stalled { border-style: dashed; border-color: color-mix(in srgb, var(--amber) 55%, transparent); }
    .node.stalled .dot { background: var(--amber); animation: pulse 1.6s infinite; }
    .node.failed { border-color: var(--red); box-shadow: 0 0 0 3px color-mix(in srgb, var(--red) 20%, transparent); }
    .node.failed .dot { background: var(--red); }
    .node.waiting { border-color: var(--amber); box-shadow: 0 0 0 3px color-mix(in srgb, var(--amber) 18%, transparent); }
    .node.waiting .dot { background: var(--amber); animation: pulse 1.6s infinite; }
    .node.skipped { opacity: .38; }

    .node.type-condition .icon { background: color-mix(in srgb, var(--amber) 14%, var(--panel-2)); }
    .node.type-agent .icon { background: color-mix(in srgb, var(--accent) 14%, var(--panel-2)); }
    .node.type-await_human .icon, .node.type-await_event .icon { background: color-mix(in srgb, var(--amber) 10%, var(--panel-2)); }

    .edge { stroke: #3a4552; stroke-width: 1.6; fill: none; }
    .edge.active { stroke: var(--green); }
    .edge-label { font: 600 10.5px -apple-system, sans-serif; fill: var(--faint); }
    .edge-label.active { fill: var(--green); }

    /* ---- sidebar ---- */
    aside { border-left: 1px solid var(--border); background: var(--panel); overflow-y: auto; display: flex; flex-direction: column; }
    .side-section { padding: 16px 18px; border-bottom: 1px solid var(--border); }
    .side-section h3 { margin: 0 0 10px; font-size: 11px; text-transform: uppercase; letter-spacing: .8px; color: var(--faint); }

    #interrupt-panel { background: color-mix(in srgb, var(--amber) 7%, var(--panel)); }
    #interrupt-panel .reason { font-weight: 650; margin-bottom: 12px; }
    #interrupt-panel label { display: block; font-size: 12px; color: var(--muted); margin: 10px 0 4px; }
    #interrupt-panel textarea, #interrupt-panel input[type=text], #interrupt-panel input[type=number] {
        width: 100%; background: var(--bg); color: var(--text); border: 1px solid var(--border);
        border-radius: 8px; padding: 8px 10px; font: inherit; font-size: 13px;
    }
    .seg { display: flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
    .seg label { flex: 1; margin: 0 !important; text-align: center; padding: 7px 0; cursor: pointer; font-size: 13px; color: var(--muted); background: var(--bg); }
    .seg input { display: none; }
    .seg input:checked + span { color: var(--text); font-weight: 650; }
    .seg label:has(input:checked) { background: var(--panel-2); }
    .seg label + label { border-left: 1px solid var(--border); }

    #failure-panel { background: color-mix(in srgb, var(--red) 7%, var(--panel)); }
    #failure-panel .reason { color: #ffb4ad; font-size: 13px; }

    table.attempts { width: 100%; border-collapse: collapse; font-size: 12px; }
    .attempts td { padding: 6px 4px; border-bottom: 1px solid var(--border); vertical-align: top; }
    .attempts tr:last-child td { border-bottom: 0; }
    .attempts .err { color: var(--red); font-size: 11px; }

    #state-json {
        margin: 0; font-family: var(--mono); font-size: 11.5px; line-height: 1.55;
        white-space: pre-wrap; word-break: break-word; color: var(--muted);
    }
    .tabs { display: flex; gap: 4px; padding: 10px 12px 0; }
    .tabs button { flex: 1; background: none; border: none; border-bottom: 2px solid transparent; color: var(--faint); font: inherit; font-size: 12.5px; font-weight: 650; padding: 6px; cursor: pointer; }
    .tabs button.on { color: var(--text); border-bottom-color: var(--accent); }
</style>
@endsection

@section('content')
<div class="layout">
    <section id="canvas">
        <svg id="edges">
            <defs>
                <marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6.5" markerHeight="6.5" orient="auto-start-reverse">
                    <path d="M 0 1 L 9 5 L 0 9" fill="none" stroke="#3a4552" stroke-width="1.8"/>
                </marker>
                <marker id="arrow-active" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6.5" markerHeight="6.5" orient="auto-start-reverse">
                    <path d="M 0 1 L 9 5 L 0 9" fill="none" stroke="var(--green)" stroke-width="1.8"/>
                </marker>
            </defs>
        </svg>
        <div id="graph"></div>
    </section>

    <aside>
        <div id="stalled-panel" class="side-section" style="display:none;">
            <h3>⏳ Waiting for a queue worker</h3>
            <div class="muted" style="font-size:13px;">
                This run is queued, but no worker has claimed its next step.
                It will sit here until one runs:
            </div>
            <pre class="mono" style="margin:8px 0 0; white-space:pre-wrap;">php artisan queue:work</pre>
        </div>
        <div id="interrupt-panel" class="side-section" style="display:none;"></div>
        <div id="failure-panel" class="side-section" style="display:none;"></div>

        <div class="tabs">
            <button id="tab-steps" class="on" onclick="showTab('steps')">Step attempts</button>
            <button id="tab-state" onclick="showTab('state')">State bag</button>
        </div>
        <div id="panel-steps" class="side-section" style="border-bottom:0;"></div>
        <div id="panel-state" class="side-section" style="display:none; border-bottom:0;">
            <pre id="state-json"></pre>
        </div>
    </aside>
</div>
@endsection

@section('script')
<script>
    const GRAPH = @json($graph);
    let DATA = @json($data);

    const RESUME_URL = '{{ route('agent-workflows.resume', $run) }}';
    const DATA_URL = '{{ route('agent-workflows.show.data', $run) }}';

    const ICONS = {
        agent: '✦', callback: 'ƒ', condition: '⑂', parallel: '⫴',
        evaluate: '↻', await_human: '✋', await_event: '⚡',
    };
    const KINDS = {
        agent: 'agent', callback: 'step', condition: 'condition', parallel: 'parallel',
        evaluate: 'evaluator loop', await_human: 'human gate', await_event: 'event gate',
    };

    /* ---------- graph rendering (once) ---------- */

    const graphEl = document.getElementById('graph');
    const svg = document.getElementById('edges');
    const canvas = document.getElementById('canvas');

    function renderGraph() {
        if (!GRAPH) {
            graphEl.innerHTML = '<div class="muted">This run\'s workflow is not registered — no diagram available.</div>';
            return;
        }

        graphEl.innerHTML = GRAPH.rows.map(row => `
            <div class="row">${row.map(id => {
                const n = GRAPH.nodes[id];
                return `
                    <div class="node type-${n.type}" id="node-${cssId(id)}">
                        <div class="head">
                            <div class="icon">${ICONS[n.type] ?? '•'}</div>
                            <div>
                                <div class="label">${esc(n.label)}</div>
                                <div class="kind">${KINDS[n.type] ?? n.type}</div>
                            </div>
                        </div>
                        <div class="detail">${esc(n.detail ?? '')}</div>
                        <div class="foot"><span class="dot"></span><span class="status-text">—</span></div>
                    </div>`;
            }).join('')}</div>`).join('');
    }

    function cssId(id) { return id.replace(/[^a-zA-Z0-9_-]/g, '_'); }
    function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
    function nodeEl(id) { return document.getElementById('node-' + cssId(id)); }

    function drawEdges() {
        if (!GRAPH) return;

        svg.querySelectorAll('path.edge, text.edge-label').forEach(el => el.remove());
        svg.setAttribute('width', canvas.scrollWidth);
        svg.setAttribute('height', canvas.scrollHeight);

        const base = graphEl.getBoundingClientRect();
        const ox = graphEl.offsetLeft, oy = graphEl.offsetTop;

        for (const e of GRAPH.edges) {
            const a = nodeEl(e.from)?.getBoundingClientRect();
            const b = nodeEl(e.to)?.getBoundingClientRect();
            if (!a || !b) continue;

            const x1 = a.left - base.left + a.width / 2 + ox, y1 = a.bottom - base.top + oy;
            const x2 = b.left - base.left + b.width / 2 + ox, y2 = b.top - base.top + oy - 3;
            const my = (y1 + y2) / 2;

            const active = edgeActive(e);

            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', `M ${x1} ${y1} C ${x1} ${my}, ${x2} ${my}, ${x2} ${y2}`);
            path.setAttribute('class', 'edge' + (active ? ' active' : ''));
            path.setAttribute('marker-end', active ? 'url(#arrow-active)' : 'url(#arrow)');
            svg.appendChild(path);

            if (e.label) {
                const t = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                t.setAttribute('x', (x1 + x2) / 2 + (x2 > x1 ? 8 : -8));
                t.setAttribute('y', my - 5);
                t.setAttribute('text-anchor', x2 >= x1 ? 'start' : 'end');
                t.setAttribute('class', 'edge-label' + (active ? ' active' : ''));
                t.textContent = e.label;
                svg.appendChild(t);
            }
        }
    }

    /* ---------- live data overlay ---------- */

    function rowsFor(id) { return DATA.steps.filter(s => s.step_id === id); }
    function latestFor(id) { const r = rowsFor(id); return r[r.length - 1] ?? null; }

    function nodeStatus(id) {
        const node = GRAPH.nodes[id];
        const latest = latestFor(id);

        if (!latest) {
            if (node.branchOf) {
                const chosen = DATA.run.state?.steps?.[node.branchOf]?.branch;
                if (chosen && chosen !== id) return 'skipped';
            }
            if (DATA.run.current_step === id && DATA.run.status === 'pending') {
                return DATA.run.stalled ? 'stalled' : 'queued';
            }
            return 'idle';
        }

        if (latest.status === 'running') return 'running';
        if (latest.status === 'failed') return 'failed';
        if (latest.status === 'interrupted') {
            return DATA.interrupt?.step_id === id ? 'waiting' : 'completed';
        }
        return 'completed';
    }

    function edgeActive(e) {
        const from = nodeStatus(e.from), to = nodeStatus(e.to);
        return ['completed', 'running', 'waiting', 'failed'].includes(from)
            && !['idle', 'skipped'].includes(to);
    }

    function tokensOf(usage) {
        if (!usage) return null;
        const t = (usage.prompt_tokens ?? 0) + (usage.completion_tokens ?? 0);
        return t > 0 ? t : null;
    }

    const STATUS_TEXT = {
        idle: 'not run', queued: 'queued', stalled: 'queued — no worker?', running: 'running…',
        completed: 'completed', failed: 'failed', waiting: 'waiting', skipped: 'skipped',
    };

    function applyData() {
        if (!GRAPH) return;

        for (const id of Object.keys(GRAPH.nodes)) {
            const el = nodeEl(id);
            if (!el) continue;

            const status = nodeStatus(id);
            el.className = `node type-${GRAPH.nodes[id].type} ${status}`;

            const rows = rowsFor(id);
            const latest = rows[rows.length - 1];
            const bits = [STATUS_TEXT[status]];

            if (latest) {
                if (rows.length > 1) bits.push(rows.length + ' attempts');
                if (latest.finished_at) bits.push(duration(latest.started_at, latest.finished_at));
                const tok = tokensOf(latest.usage);
                if (tok) bits.push(tok + ' tok');
            }

            el.querySelector('.status-text').textContent = bits.join(' · ');
        }

        renderHeader();
        renderInterrupt();
        renderFailure();
        renderAttempts();
        renderState();
        drawEdges();
    }

    function renderHeader() {
        const chip = document.getElementById('run-chip');
        chip.className = 'chip ' + DATA.run.status;
        chip.textContent = statusLabel(DATA.run.status);

        document.getElementById('drift-badge').style.display = DATA.run.drifted ? '' : 'none';
        document.getElementById('stalled-panel').style.display = DATA.run.stalled ? '' : 'none';
        document.getElementById('retry-form').style.display = DATA.run.status === 'failed' ? '' : 'none';
        document.getElementById('cancel-form').style.display =
            ['completed', 'cancelled'].includes(DATA.run.status) ? 'none' : '';
    }

    // Rebuilding the panel's innerHTML on every poll would wipe whatever
    // the reviewer has typed or selected, so it only re-renders when the
    // interrupt itself changes.
    let interruptKey = null;

    function renderInterrupt() {
        const panel = document.getElementById('interrupt-panel');

        if (!DATA.interrupt || !['awaiting_human', 'awaiting_event'].includes(DATA.run.status)) {
            interruptKey = null;
            panel.style.display = 'none';
            return;
        }

        const key = [DATA.run.status, DATA.interrupt.step_id, DATA.interrupt.type, DATA.interrupt.created_at].join('|');

        if (key === interruptKey) {
            return;
        }

        interruptKey = key;
        panel.style.display = '';

        if (DATA.interrupt.type === 'event') {
            panel.innerHTML = `
                <h3>⚡ Waiting for event</h3>
                <div class="reason">${esc(DATA.interrupt.reason ?? '')}</div>
                <div class="muted" style="font-size:12.5px;">Deliver it from your app:</div>
                <pre class="mono" style="white-space:pre-wrap;">$run->deliverEvent('${esc(DATA.interrupt.context?.event ?? '')}', [...]);</pre>`;
            return;
        }

        const fields = Object.entries(DATA.interrupt.response_schema ?? {}).map(([name, rules]) => {
            const r = Array.isArray(rules) ? rules.join('|') : String(rules);

            if (r.includes('boolean')) {
                return `
                    <label>${esc(name)}</label>
                    <div class="seg">
                        <label><input type="radio" name="${esc(name)}" value="1" checked><span>✓ yes</span></label>
                        <label><input type="radio" name="${esc(name)}" value="0"><span>✕ no</span></label>
                    </div>`;
            }
            if (r.includes('integer') || r.includes('numeric')) {
                return `<label>${esc(name)}${r.includes('required') ? ' *' : ''}</label>
                        <input type="number" name="${esc(name)}">`;
            }
            return `<label>${esc(name)}${r.includes('required') ? ' *' : ''}</label>
                    <textarea name="${esc(name)}" rows="2"></textarea>`;
        }).join('');

        panel.innerHTML = `
            <h3>✋ Human input required</h3>
            <div class="reason">${esc(DATA.interrupt.reason ?? 'Waiting for a response')}</div>
            <form method="POST" action="${RESUME_URL}">
                <input type="hidden" name="_token" value="${CSRF}">
                ${fields}
                <div style="margin-top:14px; display:flex; gap:8px;">
                    <button class="btn good" style="flex:1;">Resume run</button>
                </div>
                <div class="faint" style="margin-top:8px; font-size:11.5px;">
                    Validated against the step's schema, merged into state, then the run continues.
                </div>
            </form>`;
    }

    function renderFailure() {
        const panel = document.getElementById('failure-panel');

        if (DATA.run.status !== 'failed') {
            panel.style.display = 'none';
            return;
        }

        panel.style.display = '';
        panel.innerHTML = `
            <h3>✕ Run failed at <span class="mono">${esc(DATA.run.failed_step ?? '?')}</span></h3>
            <div class="reason">${esc(DATA.run.failure_reason ?? '')}</div>
            <div class="faint" style="margin-top:8px; font-size:11.5px;">
                Earlier steps keep their checkpointed results — retry re-runs only the failed step.
            </div>`;
    }

    function renderAttempts() {
        const rows = DATA.steps.map(s => `
            <tr>
                <td class="mono">${esc(s.step_id)}</td>
                <td><span class="chip ${s.status === 'interrupted' ? 'awaiting_human' : s.status}">${s.status}</span></td>
                <td class="muted">#${s.attempt}</td>
                <td class="muted">${s.finished_at ? duration(s.started_at, s.finished_at) : '—'}</td>
            </tr>
            ${s.error ? `<tr><td colspan="4" class="err">${esc(s.error)}</td></tr>` : ''}`).join('');

        document.getElementById('panel-steps').innerHTML = DATA.steps.length
            ? `<table class="attempts"><tbody>${rows}</tbody></table>`
            : '<div class="faint">No steps have executed yet.</div>';
    }

    function renderState() {
        document.getElementById('state-json').textContent =
            JSON.stringify(DATA.run.state ?? {}, null, 2);
    }

    function showTab(name) {
        for (const t of ['steps', 'state']) {
            document.getElementById('tab-' + t).classList.toggle('on', t === name);
            document.getElementById('panel-' + t).style.display = t === name ? '' : 'none';
        }
    }

    /* ---------- boot + poll ---------- */

    renderGraph();
    applyData();
    addEventListener('resize', drawEdges);

    setInterval(async () => {
        try {
            const res = await fetch(DATA_URL, { headers: { Accept: 'application/json' } });
            DATA = await res.json();
            applyData();
        } catch (e) { /* transient — keep polling */ }
    }, {{ (int) config('agent-workflows-ui.polling', 2500) }});
</script>
@endsection
