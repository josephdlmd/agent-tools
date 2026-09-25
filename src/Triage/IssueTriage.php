<?php

namespace Josephdlmd\AgentTools\Triage;

use Illuminate\Support\Facades\Log;
use Josephdlmd\AgentTools\TypeSafe\Judge;

/**
 * Decides whether an agent could take an issue on its own. Code checks the
 * facts (labels, assignees, blockers); TypeSafe answers one literal question
 * each about readiness, Filament screens and risk; code turns them into a
 * verdict. A verdict can hold an issue back but never promote one: only a
 * person's ready-for-agent label makes an issue eligible.
 */
class IssueTriage
{
    public function __construct(private readonly Judge $judge) {}

    /**
     * @param  array{number: int, title: string, body: string, labels: list<string>, assignees: list<string>, url: string}  $issue
     * @return array<string, mixed>
     */
    public function evaluate(array $issue, int $openBlockers): array
    {
        $config = (array) config('agent-tools.triage');
        $labelled = in_array($config['ready_label'], $issue['labels'], true);
        $question = in_array($config['question_label'], $issue['labels'], true);

        $judgment = $this->judge->ask([
            'title' => $issue['title'],
            'labels' => $issue['labels'],
            'body' => mb_substr($issue['body'], 0, (int) $config['body_limit']),
        ], $config['questions']);

        $answers = $judgment['answers'] ?? null;
        $noul = fn (string $key): ?float => isset($answers[$key]['noul']) ? (float) $answers[$key]['noul'] : null;

        $specified = $noul('specified');
        $screen = $noul('changes_screen');
        $risks = array_filter([
            'access control' => $noul('touches_access'),
            'money' => $noul('touches_money'),
            'deleting data' => $noul('deletes_data'),
        ], fn (?float $value): bool => $value !== null && $value >= (float) $config['risk_threshold']);

        $reasons = [];

        if ($issue['assignees'] !== []) {
            $reasons[] = 'assigned to '.implode(', ', $issue['assignees']);
        }

        if ($openBlockers > 0) {
            $reasons[] = "blocked by {$openBlockers} open issue".($openBlockers > 1 ? 's' : '');
        }

        if ($question) {
            $reasons[] = 'a question to decide, not work to do';
        }

        if ($answers === null) {
            $reasons[] = 'TypeSafe unavailable';
        } elseif ($specified !== null && $specified < (float) $config['specified_threshold']) {
            $reasons[] = $specified <= (float) $config['unspecified_threshold'] ? 'not specified enough' : 'specification unclear';
        }

        if ($risks !== []) {
            $reasons[] = 'touches '.implode(', ', array_keys($risks));
        }

        $verdict = match (true) {
            $risks !== [] => 'human',
            $answers === null, $question, $specified === null || $specified < (float) $config['specified_threshold'] => 'hold',
            $issue['assignees'] !== [], $openBlockers > 0 => 'waiting',
            ! $labelled => 'eligible once labelled',
            default => 'agent',
        };

        $result = [
            'number' => $issue['number'],
            'title' => $issue['title'],
            'labelled' => $labelled,
            'verdict' => $verdict,
            'workflow' => $screen !== null && $screen >= 0.5 ? 'Filament Blueprint' : 'standard',
            'reasons' => $reasons,
            'scores' => [
                'specified' => $specified,
                'changes_screen' => $screen,
                'touches_access' => $noul('touches_access'),
                'touches_money' => $noul('touches_money'),
                'deletes_data' => $noul('deletes_data'),
            ],
            'model' => $judgment['model'] ?? null,
            'input_tokens' => $judgment['input_tokens'] ?? null,
        ];

        rescue(fn () => Log::build([
            'driver' => 'single',
            'path' => config('agent-tools.log_path'),
        ])->info('judgment', ['event' => 'triage', ...$result]), report: false);

        return $result;
    }

    /**
     * The comment triage leaves on an issue.
     *
     * @param  array<string, mixed>  $result
     */
    public function comment(array $result): string
    {
        $headline = match ($result['verdict']) {
            'agent' => 'An agent could take this on its own.',
            'eligible once labelled' => 'An agent could take this once it is labelled `'.config('agent-tools.triage.ready_label').'`.',
            'waiting' => 'Not yet: '.implode('; ', $result['reasons']).'.',
            'human' => 'A person should drive this: it '.implode('; ', $result['reasons']).'.',
            default => 'Hold: '.implode('; ', $result['reasons']).'.',
        };

        $scores = collect($result['scores'])
            ->map(fn (?float $value, string $key): string => "{$key} ".($value === null ? '–' : number_format($value, 2)))
            ->implode(', ');

        return implode("\n", [
            config('agent-tools.triage.marker'),
            "**Agent triage** (automatic, a suggestion only): {$headline}",
            '',
            "- Workflow: {$result['workflow']}",
            "- Scores: {$scores} ({$result['model']})",
            '',
            'Only a person\'s `'.config('agent-tools.triage.ready_label').'` label makes an issue eligible; this comment never does.',
        ]);
    }
}
