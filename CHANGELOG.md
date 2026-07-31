# Changelog

All notable changes to `timmcleod/agent-workflows-ui` are documented here.

## v0.4.1 — 2026-07-31

- Compatibility: allow `timmcleod/agent-workflows` `^0.12` (static `Workflow::start()` release). No dashboard changes, no schema changes.

## v0.4.0 — 2026-07-30

- **Debate progress in the flowchart.** A debate node now carries one pip per allowed round — filled as rounds commit, pulsing on the round in flight — plus an outcome line: `✓ consensus · round 3` (green), `✕ no consensus · cap hit` (amber), or `round 2 of 4…` while running. The flow's progress is readable at a glance without leaving the graph. Applies to any step checkpointing a `steps.{id}.transcript` array, so hand-rolled debate recipes get it too.
- **Debate tab.** The transcript itself stays out of the way: a sidebar tab (shown only when a debate exists) with the outcome strip, the judge's verdict, and each round behind a collapsed accordion. Accordions the viewer opened stay open across polls; clicking the debate node switches to the tab.
- Compatibility: allow `timmcleod/agent-workflows` `^0.11` (the `debate()` release).

## v0.3.1 — 2026-07-30

- Compatibility: allow `timmcleod/agent-workflows` `^0.10` (hardening release). No dashboard changes. Note for hosts upgrading core: run `php artisan migrate` — core v0.10 ships additive migrations.

## v0.3.0 — 2026-07-28

Requires `timmcleod/agent-workflows` `^0.9`.

- Event gates are actionable: runs parked by `awaitEvent()` get a deliver form (event name from the interrupt, optional JSON payload) instead of a read-only snippet.
- The runs list filters by status group (Running, Awaiting, Failed, Completed, Cancelled) and by workflow name, and shows per-run token totals.
- Human gates with an `awaitHuman()` timeout show their deadline ("times out in 2d"), kept fresh without rebuilding the form.
- The run header shows the run's total token usage.

## v0.2.2 — 2026-07-28

- Compatibility: allow `timmcleod/agent-workflows` `^0.8` (typed workflow state). No dashboard changes.

## v0.2.1 — 2026-07-28

Edge rendering fixes on the run flowchart:

- Edges below the visible fold no longer vanish — the SVG overlay is sized to the full scrollable graph instead of the viewport.
- Arrowheads are filled triangles instead of stroked chevrons.
- Edges converging on one node meet at a junction above it and share a single arrowhead, colored by whether the inbound path was taken.

## v0.2.0 — 2026-07-28

- Stalled-run hint: a run that stays queued past `stalled_after` (config, default 10s, `null` disables) with no worker claiming its next step is flagged — amber "queued — no worker?" node, a "waiting for a queue worker" panel with the `queue:work` command, and a labelled chip on the runs list. Runs parked by `awaitHuman()`/`awaitEvent()` are never flagged.

## v0.1.1 — 2026-07-28

- The approval form no longer loses its state to live polling: the interrupt panel only re-renders when the interrupt itself changes, so selections and notes survive while the page stays live.

## v0.1.0 — 2026-07-28

Initial release: a dashboard mounted at `/agent-workflows` showing every run as a live flowchart over `WorkflowDefinition::toGraph()` — step statuses overlaid, taken branches highlighted, skipped branches dimmed — with the step-attempt audit trail, the checkpointed state bag, schema-generated approval forms for `awaitHuman()` interrupts, retry-from-checkpoint, and cancel. Server-rendered Blade with polling; no build step. Open in `local`, gated by `viewAgentWorkflows` everywhere else.
