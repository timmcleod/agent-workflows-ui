<?php

namespace TimMcLeod\AgentWorkflowsUi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

/**
 * Strictly read-only: the dashboard observes runs and never acts on them.
 * Resuming, delivering events, retrying, and cancelling belong to the host
 * application through the core API, where they carry its authorization and
 * audit rules.
 */
class DashboardController
{
    public function __construct(protected WorkflowRegistry $registry) {}

    public function index(): View
    {
        return view('agent-workflows-ui::index');
    }

    public function indexData(Request $request): JsonResponse
    {
        $statuses = match ($request->query('status')) {
            'active' => [RunStatus::Pending, RunStatus::Running],
            'awaiting' => [RunStatus::AwaitingHuman, RunStatus::AwaitingEvent],
            'completed' => [RunStatus::Completed],
            'failed' => [RunStatus::Failed],
            'cancelled' => [RunStatus::Cancelled],
            default => null,
        };

        $runs = WorkflowRun::query()
            ->when($statuses !== null, fn ($query) => $query->whereIn('status', $statuses))
            ->when($request->filled('workflow'), fn ($query) => $query->where('name', $request->query('workflow')))
            ->latest('id')
            ->limit((int) config('agent-workflows-ui.runs', 50))
            ->withCount('steps')
            ->with(['steps' => fn ($query) => $query->select('run_id', 'usage')])
            ->get()
            ->map(fn (WorkflowRun $run) => [
                'id' => $run->id,
                'name' => $run->name,
                'status' => $run->status->value,
                'current_step' => $run->current_step,
                'failed_step' => $run->failed_step,
                'steps_count' => $run->steps_count,
                'stalled' => $this->isStalled($run),
                'tokens' => $run->steps->sum(fn ($step) => ($step->usage['prompt_tokens'] ?? 0) + ($step->usage['completion_tokens'] ?? 0)),
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'created_at' => $run->created_at->toIso8601String(),
            ]);

        return response()->json([
            'runs' => $runs,
            'workflows' => WorkflowRun::query()->select('name')->distinct()->orderBy('name')->pluck('name'),
        ]);
    }

    public function show(WorkflowRun $run): View
    {
        // Runs can outlive their definitions (drift, unregistered workflows);
        // the audit trail and state still render without a diagram.
        $graph = $this->registry->has($run->name)
            ? $this->registry->get($run->name)->toGraph()
            : null;

        return view('agent-workflows-ui::show', [
            'run' => $run,
            'graph' => $graph,
            'data' => $this->runPayload($run),
        ]);
    }

    public function showData(WorkflowRun $run): JsonResponse
    {
        return response()->json($this->runPayload($run));
    }

    /**
     * A queued run nobody has claimed for a while — the strongest available
     * signal that no queue worker is running. Heuristic by design: the
     * dashboard cannot portably inspect the queue backend itself.
     */
    protected function isStalled(WorkflowRun $run): bool
    {
        $threshold = config('agent-workflows-ui.stalled_after');

        return $threshold !== null
            && $run->status === RunStatus::Pending
            && $run->updated_at->lte(now()->subSeconds((int) $threshold));
    }

    /**
     * @return array<string, mixed>
     */
    protected function runPayload(WorkflowRun $run): array
    {
        $run->refresh();

        $definition = $this->registry->has($run->name) ? $this->registry->get($run->name) : null;

        $interrupt = $run->interrupts()->whereNull('resolved_at')->latest('id')->first();

        return [
            'run' => [
                'id' => $run->id,
                'name' => $run->name,
                'status' => $run->status->value,
                'current_step' => $run->current_step,
                'failed_step' => $run->failed_step,
                'failure_reason' => $run->failure_reason,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'state' => $run->state,
                'drifted' => $definition !== null && $definition->hash() !== $run->version,
                'stalled' => $this->isStalled($run),
            ],
            'steps' => $run->steps()->orderBy('id')->get()->map(fn ($step) => [
                'step_id' => $step->step_id,
                'type' => $step->type->value,
                'status' => $step->status->value,
                'attempt' => $step->attempt,
                'error' => $step->error,
                'usage' => $step->usage,
                // Per-provider-call audit (core >= 0.14); null on older cores
                // and on rows written before the calls migration ran.
                'calls' => $step->calls,
                'started_at' => $step->started_at?->toIso8601String(),
                'finished_at' => $step->finished_at?->toIso8601String(),
            ])->all(),
            'interrupt' => $interrupt === null ? null : [
                'step_id' => $interrupt->step_id,
                'type' => $interrupt->type->value,
                'reason' => $interrupt->reason,
                'response_schema' => $interrupt->response_schema,
                'context' => $interrupt->context,
                'timeout_at' => $interrupt->timeout_at?->toIso8601String(),
                'created_at' => $interrupt->created_at->toIso8601String(),
            ],
        ];
    }
}
