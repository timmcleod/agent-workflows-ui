<?php

use TimMcLeod\AgentWorkflowsUi\Http\Middleware\Authorize;

return [

    /*
    |--------------------------------------------------------------------------
    | Route Path
    |--------------------------------------------------------------------------
    |
    | The URI prefix the dashboard is mounted under.
    |
    */

    'path' => env('AGENT_WORKFLOWS_UI_PATH', 'agent-workflows'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware the dashboard routes run through. The Authorize middleware
    | permits every request in the local environment; anywhere else it
    | requires the "viewAgentWorkflows" gate, which your application must
    | define (typically in a service provider's boot method):
    |
    |     Gate::define('viewAgentWorkflows', fn ($user) => $user->isAdmin());
    |
    */

    'middleware' => [
        'web',
        Authorize::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling Interval
    |--------------------------------------------------------------------------
    |
    | How often (in milliseconds) dashboard pages refresh their data.
    |
    */

    'polling' => 2500,

    /*
    |--------------------------------------------------------------------------
    | Runs Per Page
    |--------------------------------------------------------------------------
    |
    | How many runs the dashboard index lists.
    |
    */

    'runs' => 50,

    /*
    |--------------------------------------------------------------------------
    | Stalled Threshold
    |--------------------------------------------------------------------------
    |
    | A run that stays queued (pending) longer than this many seconds with no
    | worker claiming its next step is flagged in the dashboard, since a
    | pending run with no running worker otherwise looks healthy forever.
    | Set to null to disable the hint.
    |
    */

    'stalled_after' => 10,

];
