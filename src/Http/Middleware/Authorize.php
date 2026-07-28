<?php

namespace TimMcLeod\AgentWorkflowsUi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Follows the Telescope/Horizon convention: the dashboard is open in the
 * local environment; everywhere else the host application must define a
 * "viewAgentWorkflows" gate granting access.
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->allowed($request), 403);

        return $next($request);
    }

    protected function allowed(Request $request): bool
    {
        return app()->environment('local')
            || Gate::forUser($request->user())->allows('viewAgentWorkflows');
    }
}
