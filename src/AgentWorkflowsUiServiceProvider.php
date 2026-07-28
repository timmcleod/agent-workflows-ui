<?php

namespace TimMcLeod\AgentWorkflowsUi;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AgentWorkflowsUiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-workflows-ui.php', 'agent-workflows-ui');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'agent-workflows-ui');

        Route::group([
            'prefix' => config('agent-workflows-ui.path'),
            'as' => 'agent-workflows.',
            'middleware' => config('agent-workflows-ui.middleware'),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/agent-workflows-ui.php' => config_path('agent-workflows-ui.php'),
            ], 'agent-workflows-ui-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/agent-workflows-ui'),
            ], 'agent-workflows-ui-views');
        }
    }
}
