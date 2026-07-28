<?php

namespace TimMcLeod\AgentWorkflowsUi\Tests\Fixtures;

use TimMcLeod\AgentWorkflows\WorkflowState;

class PrepareStep
{
    public function __invoke(WorkflowState $state): WorkflowState
    {
        return $state->set('prepared', true);
    }
}
