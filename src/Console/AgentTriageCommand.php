<?php

namespace Josephdlmd\AgentTools\Console;

use Illuminate\Console\Command;
use Josephdlmd\AgentTools\Triage\GitHub;
use Josephdlmd\AgentTools\Triage\IssueTriage;
use Throwable;

/**
 * Triages open GitHub issues for agent work and prints a verdict per issue.
 * It changes nothing unless --comment is passed, and then only adds a comment.
 */
class AgentTriageCommand extends Command
{
    protected $signature = 'agent:triage
        {issues?* : Issue numbers to triage (default: open issues)}
        {--limit=30 : How many open issues to read}
        {--comment : Post the verdict as a comment on each issue that has none yet}
        {--json : Print the results as JSON}';

    protected $description = 'Triage GitHub issues for agent work with TypeSafe; comments only with --comment';

    public function handle(GitHub $github, IssueTriage $triage): int
    {
        try {
            $issues = $github->openIssues((int) $this->option('limit'));
        } catch (Throwable $exception) {
            $this->error('Could not read issues: '.$exception->getMessage());

            return self::FAILURE;
        }

        $only = array_map('intval', (array) $this->argument('issues'));

        if ($only !== []) {
            $issues = array_values(array_filter($issues, fn (array $issue): bool => in_array($issue['number'], $only, true)));
        }

        $results = [];

        foreach ($issues as $issue) {
            $blockers = rescue(fn (): int => $github->openBlockers($issue['number']), 0, report: false);
            $result = $triage->evaluate($issue, $blockers);
            $results[] = $result;

            if ($this->option('comment')) {
                $marker = (string) config('agent-tools.triage.marker');

                if (! $github->hasCommentWith($issue['number'], $marker)) {
                    $github->comment($issue['number'], $triage->comment($result));
                    $result['commented'] = true;
                }
            }
        }

        if ($this->option('json')) {
            $this->output->write(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Title', 'Verdict', 'Workflow', 'Why'],
            array_map(fn (array $result): array => [
                $result['number'],
                mb_strimwidth($result['title'], 0, 48, '…'),
                $result['verdict'],
                $result['workflow'],
                implode('; ', $result['reasons']) ?: '—',
            ], $results),
        );

        return self::SUCCESS;
    }
}
