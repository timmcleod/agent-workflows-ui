@extends('agent-workflows-ui::layout')

@section('title', 'Run '.substr($run->id, -8))

@section('topbar')
    <a href="{{ route('agent-workflows.index') }}" class="muted">← runs</a>
    <span class="mono faint">{{ $run->id }}</span>
    <span id="run-chip" class="chip {{ $run->status->value }}">{{ $run->status->value }}</span>
    <span id="run-tokens" class="faint" style="font-size:12px;"></span>
    <span id="drift-badge" class="chip" style="display:none;" title="The registered definition no longer matches the one this run started with">⚠ definition drift</span>
    <span class="spacer"></span>
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
    /* Sized by drawEdges() to the full scrollable graph — CSS width/height
       here would override those attributes and clip edges below the fold. */
    #edges { position: absolute; top: 0; left: 0; pointer-events: none; }
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

    /* ---- debate progress (in the node) ---- */
    .node .rounds { margin-top: 8px; display: flex; align-items: center; gap: 5px; flex-wrap: wrap; min-height: 13px; }
    .node .rounds .pip { width: 9px; height: 9px; border-radius: 50%; border: 1.5px solid var(--border); flex: none; }
    .node .rounds .pip.done { background: var(--green); border-color: var(--green); }
    .node .rounds .pip.now { border-color: var(--blue); background: color-mix(in srgb, var(--blue) 35%, transparent); animation: pulse 1.2s infinite; }
    .node .rounds .outcome { font-size: 11px; margin-left: 3px; color: var(--faint); }
    .node .rounds .outcome.ok { color: var(--green); }
    .node .rounds .outcome.cap { color: var(--amber); }
    .node .rounds .outcome.busy { color: var(--blue); }

    /* ---- debate tab (accordion transcript) ---- */
    #panel-debate .strip { font-size: 12.5px; margin: 2px 0 12px; font-weight: 650; }
    #panel-debate .strip.ok { color: var(--green); }
    #panel-debate .strip.cap { color: var(--amber); }
    #panel-debate .strip.busy { color: var(--blue); }
    details.round { border: 1px solid var(--border); border-radius: 8px; margin: 6px 0; background: var(--bg); }
    details.round summary { cursor: pointer; padding: 7px 10px; font-size: 12px; color: var(--muted); list-style: none; display: flex; gap: 8px; align-items: center; }
    details.round summary::-webkit-details-marker { display: none; }
    details.round summary::before { content: '▸'; color: var(--faint); }
    details.round[open] summary::before { content: '▾'; }
    details.round .body { padding: 2px 10px 10px; border-top: 1px solid var(--border); }
    .d-stmt { font-size: 12px; line-height: 1.5; margin: 9px 0; color: var(--muted); }
    .d-stmt b { display: block; font-size: 11px; margin-bottom: 1px; }
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
                <marker id="arrow" viewBox="0 0 10 10" refX="8.5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                    <path d="M 0 1 L 9 5 L 0 9 Z" fill="#3a4552"/>
                </marker>
                <marker id="arrow-active" viewBox="0 0 10 10" refX="8.5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                    <path d="M 0 1 L 9 5 L 0 9 Z" fill="var(--green)"/>
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
            <button id="tab-debate" style="display:none;" onclick="showTab('debate')">Debate</button>
            <button id="tab-state" onclick="showTab('state')">State bag</button>
        </div>
        <div id="panel-steps" class="side-section" style="border-bottom:0;"></div>
        <div id="panel-debate" class="side-section" style="display:none; border-bottom:0;"></div>
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
                        ${isDebateNode(id) ? '<div class="rounds"></div>' : ''}
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
        svg.style.width = canvas.scrollWidth + 'px';
        svg.style.height = canvas.scrollHeight + 'px';

        const base = graphEl.getBoundingClientRect();
        const ox = graphEl.offsetLeft, oy = graphEl.offsetTop;

        const line = (d, active, marker) => {
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            path.setAttribute('class', 'edge' + (active ? ' active' : ''));
            if (marker) path.setAttribute('marker-end', active ? 'url(#arrow-active)' : 'url(#arrow)');
            svg.appendChild(path);
        };

        // Edges converging on one node meet at a junction above it and share
        // a single arrowhead — per-edge markers would stack at the same point.
        const byTarget = new Map();

        for (const e of GRAPH.edges) {
            if (!byTarget.has(e.to)) byTarget.set(e.to, []);
            byTarget.get(e.to).push(e);
        }

        for (const [target, edges] of byTarget) {
            const b = nodeEl(target)?.getBoundingClientRect();
            if (!b) continue;

            const x2 = b.left - base.left + b.width / 2 + ox, y2 = b.top - base.top + oy - 3;
            const jy = y2 - 12;

            for (const e of edges) {
                const a = nodeEl(e.from)?.getBoundingClientRect();
                if (!a) continue;

                const x1 = a.left - base.left + a.width / 2 + ox, y1 = a.bottom - base.top + oy;
                const my = (y1 + jy) / 2;
                const active = edgeActive(e);

                line(`M ${x1} ${y1} C ${x1} ${my}, ${x2} ${my}, ${x2} ${jy}`, active, false);

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

            line(`M ${x2} ${jy} L ${x2} ${y2}`, edges.some(edgeActive), true);
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
        renderRoundPips();
        renderDebateTab();
        drawEdges();
    }

    function renderHeader() {
        const chip = document.getElementById('run-chip');
        chip.className = 'chip ' + DATA.run.status;
        chip.textContent = statusLabel(DATA.run.status);

        const tokens = DATA.steps.reduce((sum, s) =>
            sum + (s.usage?.prompt_tokens ?? 0) + (s.usage?.completion_tokens ?? 0), 0);
        document.getElementById('run-tokens').textContent = tokens > 0 ? tokens.toLocaleString() + ' tok' : '';

        document.getElementById('drift-badge').style.display = DATA.run.drifted ? '' : 'none';
        document.getElementById('stalled-panel').style.display = DATA.run.stalled ? '' : 'none';
    }

    // Read-only: the panel shows what the run is waiting for. Acting on it
    // (resume, deliverEvent) happens in the application, not the dashboard.
    function renderInterrupt() {
        const panel = document.getElementById('interrupt-panel');

        if (!DATA.interrupt || !['awaiting_human', 'awaiting_event'].includes(DATA.run.status)) {
            panel.style.display = 'none';
            return;
        }

        panel.style.display = '';

        const deadline = `<div class="faint" style="font-size:12px; margin:-6px 0 8px;">${deadlineText(DATA.interrupt.timeout_at)}</div>`;

        const context = DATA.interrupt.context && Object.keys(DATA.interrupt.context).length
            ? `<pre class="mono" style="margin:10px 0 0; white-space:pre-wrap; word-break:break-word; font-size:11.5px; color:var(--muted);">${esc(JSON.stringify(DATA.interrupt.context, null, 2))}</pre>`
            : '';

        if (DATA.interrupt.type === 'event') {
            panel.innerHTML = `
                <h3>⚡ Waiting for event</h3>
                <div class="reason">${esc(DATA.interrupt.reason ?? '')}</div>
                ${deadline}
                <div class="mono">${esc(DATA.interrupt.context?.event ?? '?')}</div>
                <div class="faint" style="margin-top:10px; font-size:11.5px;">
                    Deliver it from your application:
                    <span class="mono">$run->deliverEvent('${esc(DATA.interrupt.context?.event ?? '...')}', [...])</span>
                </div>`;
            return;
        }

        const fields = Object.entries(DATA.interrupt.response_schema ?? {}).map(([name, rules]) => `
            <tr>
                <td class="mono">${esc(name)}</td>
                <td class="faint">${esc(Array.isArray(rules) ? rules.join('|') : String(rules))}</td>
            </tr>`).join('');

        panel.innerHTML = `
            <h3>✋ Human input required</h3>
            <div class="reason">${esc(DATA.interrupt.reason ?? 'Waiting for a response')}</div>
            ${deadline}
            ${fields ? `<table class="attempts"><tbody>${fields}</tbody></table>` : ''}
            ${context}
            <div class="faint" style="margin-top:10px; font-size:11.5px;">
                Answer it from your application: <span class="mono">$run->resume([...])</span>
            </div>`;
    }

    function deadlineText(at) {
        if (!at) return '';

        const s = (new Date(at) - Date.now()) / 1000;

        return s <= 0
            ? '⏳ Past its deadline; the next sweep acts on it.'
            : '⏳ Times out in ' + (s < 3600 ? Math.ceil(s / 60) + 'm' : s < 86400 ? Math.round(s / 3600) + 'h' : Math.round(s / 86400) + 'd');
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
                Earlier steps keep their checkpointed results. Retry it from your application:
                <span class="mono">$run->retry()</span> re-runs only the failed step.
            </div>`;
    }

    // One provider call inside an attempt: origin line, invocation id, tools.
    // Tool arguments and results are present only under the core's "full"
    // audit mode; absent keys simply render nothing.
    function callDetail(call) {
        const origin = [call.model ?? call.provider ?? '?', call.finish_reason, (tokensOf(call.usage) ?? 0).toLocaleString() + ' tok']
            .filter(Boolean).join(' · ');

        const inv = call.invocation_id
            ? `<span class="mono faint" style="font-size:10.5px;" title="${esc(call.invocation_id)}">inv …${esc(call.invocation_id.slice(-8))}</span>`
            : '';

        const payload = obj => obj === undefined
            ? ''
            : `<pre class="mono" style="margin:3px 0 0; white-space:pre-wrap; word-break:break-word; font-size:11px; color:var(--faint);">${esc(JSON.stringify(obj, null, 2))}</pre>`;

        const tools = (call.tool_calls ?? []).map(t => `
            <div style="margin-top:5px; font-size:11.5px;">⚒ <span class="mono">${esc(t.name)}</span>${payload(t.arguments)}</div>`).join('');

        const results = (call.tool_results ?? []).filter(r => r.result !== undefined || r.denied).map(r => `
            <div style="margin-top:5px; font-size:11.5px;">${r.denied ? '⛔' : '↩'} <span class="mono">${esc(r.name)}</span>${payload(r.result)}</div>`).join('');

        return `
            <div class="d-stmt">
                <b>${esc(origin)}</b>
                ${inv}
                ${tools}
                ${results}
            </div>`;
    }

    function callsExpander(s) {
        if (!Array.isArray(s.calls) || !s.calls.length) return '';

        const tokens = s.calls.reduce((sum, c) => sum + (tokensOf(c.usage) ?? 0), 0);

        return `
            <tr><td colspan="4" style="padding:0 4px 6px;">
                <details class="round" data-key="calls-${cssId(s.step_id)}-${s.attempt}">
                    <summary>${s.calls.length} call${s.calls.length === 1 ? '' : 's'}${tokens ? ' · ' + tokens.toLocaleString() + ' tok' : ''}</summary>
                    <div class="body">${s.calls.map(callDetail).join('')}</div>
                </details>
            </td></tr>`;
    }

    function renderAttempts() {
        const panel = document.getElementById('panel-steps');

        // Rebuilt every poll; keep whichever call expanders the viewer opened.
        const open = new Set([...panel.querySelectorAll('details[open]')].map(el => el.dataset.key));

        const rows = DATA.steps.map(s => `
            <tr>
                <td class="mono">${esc(s.step_id)}</td>
                <td><span class="chip ${s.status === 'interrupted' ? 'awaiting_human' : s.status}">${s.status}</span></td>
                <td class="muted">#${s.attempt}</td>
                <td class="muted">${s.finished_at ? duration(s.started_at, s.finished_at) : '—'}</td>
            </tr>
            ${s.error ? `<tr><td colspan="4" class="err">${esc(s.error)}</td></tr>` : ''}
            ${callsExpander(s)}`).join('');

        panel.innerHTML = DATA.steps.length
            ? `<table class="attempts"><tbody>${rows}</tbody></table>`
            : '<div class="faint">No steps have executed yet.</div>';

        for (const el of panel.querySelectorAll('details')) {
            if (open.has(el.dataset.key)) el.open = true;
        }
    }

    function renderState() {
        document.getElementById('state-json').textContent =
            JSON.stringify(DATA.run.state ?? {}, null, 2);
    }

    /* ---------- debate progress ---------- */

    // Any step whose checkpoint holds a transcript array is a debate — the
    // packaged debate() step and hand-rolled recipes alike.
    function debateSteps() {
        return Object.entries(DATA.run.state?.steps ?? {})
            .filter(([, s]) => Array.isArray(s?.transcript) && s.transcript.length &&
                s.transcript.every(e => e && typeof e === 'object' && 'speaker' in e && 'round' in e))
            .map(([id, s]) => ({
                id,
                transcript: s.transcript,
                judge: (s.judge && typeof s.judge === 'object') ? s.judge : null,
                satisfied: s.satisfied,
                iteration: s.iteration ?? 0,
            }));
    }

    const SPEAKER_PALETTE = ['var(--accent)', 'var(--blue)', 'var(--green)', 'var(--amber)', 'var(--red)'];

    function isDebateNode(id) {
        const n = GRAPH?.nodes[id];
        return n?.type === 'evaluate' && (n.detail ?? '').startsWith('debate ·');
    }

    // The round cap comes from the node's detail string ("… max 4 rounds"),
    // which this package family authors on both sides of the contract.
    function maxRounds(id) {
        const m = (GRAPH?.nodes[id]?.detail ?? '').match(/max (\d+) round/);
        return m ? parseInt(m[1], 10) : null;
    }

    function debateOutcome(d, max) {
        const running = latestFor(d.id)?.status === 'running';

        if (d.satisfied === true) return { cls: 'ok', text: `✓ consensus · round ${d.iteration}` };
        if (running) return { cls: 'busy', text: `round ${d.iteration + 1}${max ? ' of ' + max : ''}…` };
        if (d.satisfied === false && max && d.iteration >= max) return { cls: 'cap', text: '✕ no consensus · cap hit' };
        if (d.iteration > 0) return { cls: '', text: `${d.iteration}${max ? ' of ' + max : ''} rounds` };
        return { cls: '', text: '' };
    }

    // One pip per allowed round, filled as rounds commit; the judge's ruling
    // becomes the node's outcome line. Progress lives in the flowchart — the
    // transcript itself stays behind the Debate tab.
    function renderRoundPips() {
        for (const d of debateSteps()) {
            const el = nodeEl(d.id)?.querySelector('.rounds');
            if (!el) continue;

            const max = maxRounds(d.id) ?? d.iteration;
            const running = latestFor(d.id)?.status === 'running';
            const outcome = debateOutcome(d, max);

            let pips = '';
            for (let i = 1; i <= max; i++) {
                pips += `<span class="pip ${i <= d.iteration ? 'done' : (running && i === d.iteration + 1 ? 'now' : '')}" title="round ${i}"></span>`;
            }

            el.innerHTML = pips + (outcome.text ? `<span class="outcome ${outcome.cls}">${outcome.text}</span>` : '');

            const node = nodeEl(d.id);
            node.style.cursor = 'pointer';
            node.onclick = () => showTab('debate');
        }
    }

    // Rebuilt only when the debate itself changes, so accordions the viewer
    // opened stay open across polls.
    let debateKey = null;

    function renderDebateTab() {
        const debates = debateSteps();

        document.getElementById('tab-debate').style.display = debates.length ? '' : 'none';

        const panel = document.getElementById('panel-debate');

        if (!debates.length) {
            debateKey = null;
            panel.innerHTML = '';
            return;
        }

        const key = DATA.run.status + '|' + debates.map(d =>
            [d.id, d.transcript.length, JSON.stringify(d.judge), latestFor(d.id)?.status].join(':')).join('|');

        if (key === debateKey) return;
        debateKey = key;

        const open = new Set([...panel.querySelectorAll('details[open]')].map(el => el.dataset.key));

        panel.innerHTML = debates.map(d => debateSection(d)).join('');

        for (const el of panel.querySelectorAll('details')) {
            if (open.has(el.dataset.key)) el.open = true;
        }
    }

    function debateSection(d) {
        const speakers = [...new Set(d.transcript.map(e => e.speaker))];
        const color = s => SPEAKER_PALETTE[speakers.indexOf(s) % SPEAKER_PALETTE.length];
        const outcome = debateOutcome(d, maxRounds(d.id));

        const rounds = new Map();
        for (const e of d.transcript) {
            if (!rounds.has(e.round)) rounds.set(e.round, []);
            rounds.get(e.round).push(e);
        }

        const verdict = !d.judge ? '' : `
            <details class="round" data-key="${cssId(d.id)}-verdict">
                <summary>⚖ Judge's verdict</summary>
                <div class="body">${Object.entries(d.judge).map(([k, v]) => `
                    <div class="d-stmt"><b>${esc(k)}</b>${esc(typeof v === 'string' ? v : JSON.stringify(v))}</div>`).join('')}</div>
            </details>`;

        const roundsHtml = [...rounds.entries()].map(([r, entries]) => `
            <details class="round" data-key="${cssId(d.id)}-r${r}">
                <summary>Round ${esc(String(r))} · ${entries.length} statement${entries.length === 1 ? '' : 's'}</summary>
                <div class="body">${entries.map(e => `
                    <div class="d-stmt">
                        <b style="color:${color(e.speaker)};">${esc(e.speaker)}</b>
                        ${e.text ? esc(e.text) : '<span class="faint">(no response)</span>'}
                    </div>`).join('')}</div>
            </details>`).join('');

        return `
            <h3>⚖ ${esc(d.id)} · ${speakers.map(esc).join(' vs ')}</h3>
            ${outcome.text ? `<div class="strip ${outcome.cls}">${outcome.text}</div>` : ''}
            ${verdict}
            ${roundsHtml}`;
    }

    function showTab(name) {
        for (const t of ['steps', 'debate', 'state']) {
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
