<?php

use Illuminate\Support\Facades\Gate;
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

it('resumes an awaiting run, coercing form booleans against the schema', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);

    $this->post(route('agent-workflows.resume', $run), [
        'approved' => '1',
        'notes' => 'LGTM',
    ])->assertRedirect(route('agent-workflows.show', $run));

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['approved'])->toBeTrue()
        ->and($run->state['notes'])->toBe('LGTM')
        ->and($run->state['flaky_ran'])->toBeTrue();
});

it('returns validation errors to the form when the resume payload fails the schema', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);

    $this->from(route('agent-workflows.show', $run))
        ->post(route('agent-workflows.resume', $run), ['notes' => 'missing approval'])
        ->assertRedirect(route('agent-workflows.show', $run))
        ->assertSessionHasErrors('approved');

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingHuman);
});

it('retries a failed run from the dashboard', function () {
    authorizeDashboard();

    FlakyStep::$fail = true;

    $run = AgentWorkflow::start('signoff-flow', []);

    try {
        $run->resume(['approved' => true]);
    } catch (RuntimeException) {
        // The sync queue unwinds the step failure into the caller.
    }

    expect($run->refresh()->status)->toBe(RunStatus::Failed);

    FlakyStep::$fail = false;

    $this->post(route('agent-workflows.retry', $run))
        ->assertRedirect(route('agent-workflows.show', $run));

    expect($run->refresh()->status)->toBe(RunStatus::Completed);
});

it('rejects retrying a run that is not failed', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);

    $this->from(route('agent-workflows.show', $run))
        ->post(route('agent-workflows.retry', $run))
        ->assertSessionHasErrors('retry');
});

it('cancels a parked run from the dashboard', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);

    $this->post(route('agent-workflows.cancel', $run))
        ->assertRedirect(route('agent-workflows.show', $run));

    expect($run->refresh()->status)->toBe(RunStatus::Cancelled);
});

it('marks drifted runs in the payload', function () {
    authorizeDashboard();

    $run = AgentWorkflow::start('signoff-flow', []);
    $run->update(['version' => 'stale-hash']);

    $this->getJson(route('agent-workflows.show.data', $run))
        ->assertJsonPath('run.drifted', true);
});

it('honours the configured path prefix', function () {
    expect(route('agent-workflows.index', absolute: false))->toBe('/agent-workflows');
});
