# Agent Workflows UI

[![tests](https://github.com/timmcleod/agent-workflows-ui/actions/workflows/tests.yml/badge.svg)](https://github.com/timmcleod/agent-workflows-ui/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/timmcleod/agent-workflows-ui)](https://packagist.org/packages/timmcleod/agent-workflows-ui)

A dashboard for [`timmcleod/agent-workflows`](https://github.com/timmcleod/agent-workflows). Install it alongside the core package and get a live view of every workflow run: the definition rendered as a flowchart with each step's status overlaid, the step-by-step audit trail with attempts and token counts, and the checkpointed state bag.

![The dashboard: a completed run rendered as a flowchart, the taken branch highlighted and the untaken branch dimmed, with the step-attempt audit trail alongside](https://raw.githubusercontent.com/timmcleod/agent-workflows-ui/main/art/dashboard.png)

One run, most of the package: this workflow took the high-risk escalation branch (the auto-approve branch dimmed as skipped), parked at the human gate — the audit trail shows the gate interrupted on attempt #1, then completed on attempt #2 after sign-off arrived via `resume()` — and finished with the summary agent. Note every step shows exactly one execution: checkpointed results are never re-run, and their tokens are never paid twice.

It is not read-only where it matters:

- **Approve from the browser.** Runs parked by `awaitHuman()` show a form generated from the step's validation schema; submitting it calls `resume()` and the run continues.
- **Retry from the checkpoint.** Failed runs get a retry button that re-runs only the failed step — earlier steps keep their results.
- **Cancel** any run that isn't already terminal.

The dashboard is plain server-rendered Blade with light polling — no build step, no assets to publish, nothing to go stale after upgrades.

> **Status: pre-release.** Tracks the pre-1.0 core package; APIs and screens may change.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `timmcleod/agent-workflows` ^0.7

## Installation

```bash
composer require timmcleod/agent-workflows-ui
```

That's it — the dashboard is mounted at `/agent-workflows`.

## Authorization

Like Horizon and Telescope, the dashboard is open in the `local` environment. In every other environment, all requests are refused until your application defines a `viewAgentWorkflows` gate:

```php
// app/Providers/AppServiceProvider.php

use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewAgentWorkflows', function ($user) {
        return $user->isAdmin();
    });
}
```

Treat access as sensitive: the dashboard exposes run state (which may contain document text and agent output) and can approve pending sign-offs.

## Configuration

```bash
php artisan vendor:publish --tag=agent-workflows-ui-config
```

```php
return [
    // URI prefix the dashboard is mounted under.
    'path' => env('AGENT_WORKFLOWS_UI_PATH', 'agent-workflows'),

    // Middleware for the dashboard routes.
    'middleware' => ['web', TimMcLeod\AgentWorkflowsUi\Http\Middleware\Authorize::class],

    // How often (ms) pages refresh their data.
    'polling' => 2500,

    // How many runs the index lists.
    'runs' => 50,
];
```

Views can be overridden the standard way: `php artisan vendor:publish --tag=agent-workflows-ui-views`.

## Notes

- Steps execute on your queue as usual; the dashboard just observes. With a running worker you'll watch steps light up as they complete.
- Runs whose workflow is no longer registered (or whose definition has drifted since they started) still render their audit trail and state; drifted runs are badged.

## License

MIT
