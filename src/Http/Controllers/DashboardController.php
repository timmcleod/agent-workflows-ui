<?php

namespace TimMcLeod\AgentWorkflowsUi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Models\WorkflowRun;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

class DashboardController
{
    public function __construct(protected WorkflowRegistry $registry) {}

    public function index(): View
    {
        return view('agent-workflows-ui::index');
    }

    public function indexData(): JsonResponse
    {
        $runs = WorkflowRun::query()
            ->latest('id')
            ->limit((int) config('agent-workflows-ui.runs', 50))
            ->withCount('steps')
            ->get()
            ->map(fn (WorkflowRun $run) => [
                'id' => $run->id,
                'name' => $run->name,
                'status' => $run->status->value,
                'current_step' => $run->current_step,
                'failed_step' => $run->failed_step,
                'steps_count' => $run->steps_count,
                'stalled' => $this->isStalled($run),
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'created_at' => $run->created_at->toIso8601String(),
            ]);

        return response()->json(['runs' => $runs]);
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

    public function resume(Request $request, WorkflowRun $run): RedirectResponse
    {
        $payload = collect($request->except(['_token']))
            ->map(fn ($value) => $value === '' ? null : $value)
            ->all();

        // Form posts arrive as strings; make booleans real booleans before
        // they hit the interrupt's validation schema and the state bag.
        $interrupt = $run->interrupts()->whereNull('resolved_at')->latest('id')->first();
        $schema = $interrupt !== null ? ($interrupt->response_schema ?? []) : [];

        foreach ($schema as $field => $rules) {
            $rules = is_array($rules) ? implode('|', $rules) : $rules;

            if (str_contains($rules, 'boolean') && ($payload[$field] ?? null) !== null) {
                $payload[$field] = filter_var($payload[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        try {
            $run->resume($payload, by: $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (WorkflowException $e) {
            return back()->withErrors(['resume' => $e->getMessage()]);
        }

        return redirect()->route('agent-workflows.show', $run);
    }

    public function retry(WorkflowRun $run): RedirectResponse
    {
        try {
            $run->retry();
        } catch (WorkflowException $e) {
            return back()->withErrors(['retry' => $e->getMessage()]);
        }

        return redirect()->route('agent-workflows.show', $run);
    }

    public function cancel(WorkflowRun $run): RedirectResponse
    {
        try {
            $run->cancel();
        } catch (WorkflowException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        return redirect()->route('agent-workflows.show', $run);
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
                'started_at' => $step->started_at?->toIso8601String(),
                'finished_at' => $step->finished_at?->toIso8601String(),
            ])->all(),
            'interrupt' => $interrupt === null ? null : [
                'step_id' => $interrupt->step_id,
                'type' => $interrupt->type->value,
                'reason' => $interrupt->reason,
                'response_schema' => $interrupt->response_schema,
                'context' => $interrupt->context,
                'created_at' => $interrupt->created_at->toIso8601String(),
            ],
        ];
    }
}
