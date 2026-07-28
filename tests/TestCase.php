<?php

namespace TimMcLeod\AgentWorkflowsUi\Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use TimMcLeod\AgentWorkflows\AgentWorkflowsServiceProvider;
use TimMcLeod\AgentWorkflowsUi\AgentWorkflowsUiServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            AgentWorkflowsServiceProvider::class,
            AgentWorkflowsUiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // The dashboard routes use the "web" group (encrypted cookies).
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/timmcleod/agent-workflows/database/migrations');
    }
}
