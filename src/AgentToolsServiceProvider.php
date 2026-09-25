<?php

namespace Josephdlmd\AgentTools;

use Illuminate\Support\ServiceProvider;
use Josephdlmd\AgentTools\Console\AgentJudgeCommand;

class AgentToolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-tools.php', 'agent-tools');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/agent-tools.php' => config_path('agent-tools.php'),
        ], 'agent-tools-config');

        $this->commands([AgentJudgeCommand::class]);
    }
}
