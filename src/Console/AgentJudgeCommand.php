<?php

namespace Josephdlmd\AgentTools\Console;

use Closure;
use Illuminate\Console\Command;
use Josephdlmd\AgentTools\Hooks\StopGuard;
use Throwable;

/**
 * Entry point for Claude Code hooks: reads the hook's JSON from stdin and
 * prints hook JSON only when it has something to say. It always exits 0, so a
 * failure never blocks the agent.
 */
class AgentJudgeCommand extends Command
{
    /**
     * The container key of a closure returning the hook input; tests replace it.
     */
    public const INPUT = 'agent-tools.hook-input';

    protected $signature = 'agent:judge {event : The hook event to judge (stop)}';

    protected $description = 'Judge a Claude Code hook event with TypeSafe and print the hook response';

    public function handle(StopGuard $stopGuard): int
    {
        try {
            /** @var Closure(): string $readInput */
            $readInput = app()->bound(self::INPUT)
                ? app(self::INPUT)
                : fn (): string => (string) stream_get_contents(STDIN);

            $input = json_decode($readInput(), true);

            $output = match ($this->argument('event')) {
                'stop' => is_array($input) ? $stopGuard->evaluate($input) : null,
                default => null,
            };

            if ($output !== null) {
                $this->output->write(json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        } catch (Throwable) {
            // Fail open: a broken guard must never stop the agent.
        }

        return self::SUCCESS;
    }
}
