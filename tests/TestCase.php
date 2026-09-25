<?php

namespace Josephdlmd\AgentTools\Tests;

use Josephdlmd\AgentTools\AgentToolsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AgentToolsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('agent-tools.typesafe.key', 'test-key');
        $app['config']->set('agent-tools.log_path', sys_get_temp_dir().'/agent-tools-test.log');
    }
}
