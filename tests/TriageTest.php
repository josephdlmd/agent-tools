<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Josephdlmd\AgentTools\Triage\IssueTriage;

function issue(array $overrides = []): array
{
    return [
        'number' => 42,
        'title' => 'Show the Order total in the list',
        'body' => "## What to build\nAdd a Total column.\n\n## Acceptance criteria\n- [ ] Total column, right-aligned",
        'labels' => ['ready-for-agent'],
        'assignees' => [],
        'url' => 'https://github.com/acme/app/issues/42',
        ...$overrides,
    ];
}

function fakeTriage(array $nouls): void
{
    $answers = [];

    foreach (['specified' => 0.9, 'changes_screen' => 0.9, 'touches_access' => 0.05, 'touches_money' => 0.05, 'deletes_data' => 0.05, ...$nouls] as $key => $value) {
        $answers[$key] = ['type' => 'noul', 'noul' => $value];
    }

    Http::fake(['*' => Http::response(['model' => 'jev-1.13.0', 'answers' => $answers])]);
}

it('lets an agent take a labelled, specified, low-risk issue', function (): void {
    fakeTriage([]);

    $result = app(IssueTriage::class)->evaluate(issue(), 0);

    expect($result['verdict'])->toBe('agent')
        ->and($result['workflow'])->toBe('Filament Blueprint')
        ->and($result['reasons'])->toBe([]);
});

it('sends risky issues to a person whatever their label', function (): void {
    fakeTriage(['touches_access' => 0.93, 'deletes_data' => 0.8]);

    $result = app(IssueTriage::class)->evaluate(issue(), 0);

    expect($result['verdict'])->toBe('human')
        ->and(implode(' ', $result['reasons']))->toContain('access control')->toContain('deleting data');
});

it('holds unclear issues and questions', function (): void {
    fakeTriage(['specified' => 0.2]);
    expect(app(IssueTriage::class)->evaluate(issue(), 0)['verdict'])->toBe('hold');

    fakeTriage([]);
    expect(app(IssueTriage::class)->evaluate(issue(['labels' => ['question']]), 0)['verdict'])->toBe('hold');
});

it('never promotes an unlabelled issue and waits on blockers and assignees', function (): void {
    fakeTriage([]);

    expect(app(IssueTriage::class)->evaluate(issue(['labels' => ['needs-triage']]), 0)['verdict'])->toBe('eligible once labelled')
        ->and(app(IssueTriage::class)->evaluate(issue(), 2)['verdict'])->toBe('waiting')
        ->and(app(IssueTriage::class)->evaluate(issue(['assignees' => ['someone']]), 0)['verdict'])->toBe('waiting');
});

it('holds everything when TypeSafe is unavailable', function (): void {
    config(['agent-tools.typesafe.key' => null]);

    expect(app(IssueTriage::class)->evaluate(issue(), 0)['verdict'])->toBe('hold');
});

it('prints verdicts without touching GitHub unless asked to comment', function (): void {
    fakeTriage([]);
    Process::fake(function (PendingProcess $process) {
        $command = implode(' ', (array) $process->command);

        return match (true) {
            str_contains($command, 'issue list') => Process::result(json_encode([[
                'number' => 42, 'title' => 'Show the Order total in the list', 'body' => 'Add a Total column.',
                'labels' => [['name' => 'ready-for-agent']], 'assignees' => [], 'url' => 'https://github.com/acme/app/issues/42',
            ]])),
            str_contains($command, 'api') => Process::result(json_encode(['issue_dependencies_summary' => ['blocked_by' => 0]])),
            str_contains($command, 'issue view') => Process::result(json_encode(['comments' => []])),
            default => Process::result(''),
        };
    });

    expect(Artisan::call('agent:triage'))->toBe(0)
        ->and(Artisan::output())->toContain('agent');
    Process::assertNotRan(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'issue comment'));

    Artisan::call('agent:triage', ['--comment' => true]);
    Process::assertRan(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'issue comment 42')
        && str_contains((string) $process->input, '<!-- agent-tools:triage -->'));
});
