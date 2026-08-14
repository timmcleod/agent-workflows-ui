<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\WorkflowDefinition;
use TimMcLeod\AgentWorkflowsUi\Tests\Fixtures\FlakyStep;
use TimMcLeod\AgentWorkflowsUi\Tests\Fixtures\PrepareStep;

beforeEach(function () {
    FlakyStep::$fail = false;

    defineWorkflow('signoff-flow', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class)
        ->awaitHuman(reason: 'Sign off required', schema: [
            'approved' => 'required|boolean',
            'notes' => 'nullable|string',
        ], as: 'gate')
        ->step(FlakyStep::class));
});

function authorizeDashboard(): void
{
    Gate::define('viewAgentWorkflows', fn ($user = null) => true);
}

it('forbids access outside local when no gate is defined', function () {
    $this->get(route('agent-workflows.index'))->assertForbidden();
});

it('grants access via the viewAgentWorkflows gate', function () {
    authorizeDashboard();

    $this->get(route('agent-workflows.index'))->assertOk()->assertSee('Runs');
});

it('lists runs as json', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', ['doc' => 'x']);

    $this->getJson(route('agent-workflows.data'))
        ->assertOk()
        ->assertJsonPath('runs.0.id', $run->id)
        ->assertJsonPath('runs.0.status', 'awaiting_human');
});

it('shows a run with its graph and live payload', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);

    $this->get(route('agent-workflows.show', $run))
        ->assertOk()
        ->assertSee('gate')
        ->assertSee('Sign off required');

    $this->getJson(route('agent-workflows.show.data', $run))
        ->assertOk()
        ->assertJsonPath('run.status', 'awaiting_human')
        ->assertJsonPath('run.drifted', false)
        ->assertJsonPath('interrupt.step_id', 'gate')
        ->assertJsonPath('interrupt.response_schema.approved', 'required|boolean');
});

it('renders runs whose workflow is not registered without a graph', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);
    $run->update(['name' => 'gone-flow']);

    $this->get(route('agent-workflows.show', $run))->assertOk();

    $this->getJson(route('agent-workflows.show.data', $run))
        ->assertOk()
        ->assertJsonPath('run.drifted', false);
});

it('registers no mutating routes', function () {
    foreach (['resume', 'deliver', 'retry', 'cancel'] as $action) {
        expect(Route::has('agent-workflows.'.$action))->toBeFalse();
    }
});

it('returns 404 for the removed action endpoints', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);

    foreach (['resume', 'deliver', 'retry', 'cancel'] as $action) {
        $this->post("/agent-workflows/runs/{$run->id}/{$action}")->assertNotFound();
    }

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingHuman);
});

it('shows the read-only hints instead of action forms on the run page', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);

    $this->get(route('agent-workflows.show', $run))
        ->assertOk()
        ->assertSee('$run->resume', false)
        ->assertSee('$run->retry()', false)
        ->assertDontSee("runs/{$run->id}/resume")
        ->assertDontSee("runs/{$run->id}/retry");
});

it('exposes failure details in the payload for the read-only failure panel', function () {
    authorizeDashboard();

    FlakyStep::$fail = true;

    $run = AgentWorkflow::start('signoff-flow', []);

    try {
        $run->resume(['approved' => true]);
    } catch (RuntimeException) {
        // The sync queue unwinds the step failure into the caller.
    }

    $this->getJson(route('agent-workflows.show.data', $run))
        ->assertOk()
        ->assertJsonPath('run.status', 'failed')
        ->assertJsonPath('run.failed_step', 'FlakyStep')
        ->assertJsonPath('run.failure_reason', 'Flaky step exploded.');
});

it('marks drifted runs in the payload', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);
    $run->update(['version' => 'stale-hash']);

    $this->getJson(route('agent-workflows.show.data', $run))
        ->assertJsonPath('run.drifted', true);
});

it('flags a run stuck in pending as stalled once the threshold passes', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);
    $run->updateQuietly(['status' => RunStatus::Pending, 'updated_at' => now()->subSeconds(30)]);

    $this->getJson(route('agent-workflows.show.data', $run))
        ->assertJsonPath('run.stalled', true);

    $this->getJson(route('agent-workflows.data'))
        ->assertJsonPath('runs.0.stalled', true);
});

it('does not flag fresh pending runs or parked runs as stalled', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);

    // Parked awaiting a human for ages: waiting is its job, not a stall.
    $run->updateQuietly(['updated_at' => now()->subSeconds(300)]);

    $this->getJson(route('agent-workflows.show.data', $run))
        ->assertJsonPath('run.status', 'awaiting_human')
        ->assertJsonPath('run.stalled', false);

    $run->updateQuietly(['status' => RunStatus::Pending, 'updated_at' => now()]);

    $this->getJson(route('agent-workflows.show.data', $run))
        ->assertJsonPath('run.stalled', false);
});

it('disables the stalled hint when the threshold is null', function () {
    authorizeDashboard();

    config(['agent-workflows-ui.stalled_after' => null]);

    $run = AgentWorkflow::start('signoff-flow', []);
    $run->updateQuietly(['status' => RunStatus::Pending, 'updated_at' => now()->subSeconds(300)]);

    $this->getJson(route('agent-workflows.show.data', $run))
        ->assertJsonPath('run.stalled', false);
});

it('filters the runs list by status group and workflow name', function () {
    authorizeDashboard();

    defineWorkflow('other-flow', fn (WorkflowDefinition $workflow) => $workflow
        ->step(PrepareStep::class));

    $awaiting = AgentWorkflow::start('signoff-flow', []);
    $done = AgentWorkflow::start('other-flow', []);

    $this->getJson(route('agent-workflows.data', ['status' => 'awaiting']))
        ->assertJsonCount(1, 'runs')
        ->assertJsonPath('runs.0.id', $awaiting->id);

    $this->getJson(route('agent-workflows.data', ['status' => 'completed']))
        ->assertJsonCount(1, 'runs')
        ->assertJsonPath('runs.0.id', $done->id);

    $this->getJson(route('agent-workflows.data', ['workflow' => 'other-flow']))
        ->assertJsonCount(1, 'runs')
        ->assertJsonPath('workflows', ['other-flow', 'signoff-flow']);

    $this->getJson(route('agent-workflows.data'))->assertJsonCount(2, 'runs');
});

it('reports token totals per run', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);
    $run->steps()->update(['usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30]]);

    $this->getJson(route('agent-workflows.data'))
        ->assertJsonPath('runs.0.tokens', 300); // 2 steps × 150
});

it('exposes the interrupt deadline for gates with a timeout', function () {
    authorizeDashboard();

    defineWorkflow('deadline-flow', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitHuman(reason: 'Sign off', timeout: 3600));

    $run = AgentWorkflow::start('deadline-flow', []);

    $data = $this->getJson(route('agent-workflows.show.data', $run))->json();

    expect($data['interrupt']['timeout_at'])->not->toBeNull();
});

it('keeps event gate details in the payload for the read-only panel', function () {
    authorizeDashboard();

    defineWorkflow('event-flow', fn (WorkflowDefinition $workflow) => $workflow
        ->awaitEvent('payment.settled')
        ->step(PrepareStep::class));

    $run = AgentWorkflow::start('event-flow', []);

    expect($run->status)->toBe(RunStatus::AwaitingEvent);

    $this->getJson(route('agent-workflows.show.data', $run))
        ->assertOk()
        ->assertJsonPath('interrupt.type', 'event')
        ->assertJsonPath('interrupt.context.event', 'payment.settled');
});

it('honours the configured path prefix', function () {
    expect(route('agent-workflows.index', absolute: false))->toBe('/agent-workflows');
});
