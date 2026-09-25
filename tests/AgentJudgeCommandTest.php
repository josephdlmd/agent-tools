<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Josephdlmd\AgentTools\Console\AgentJudgeCommand;

function judge(string $event, string $input): string
{
    app()->instance(AgentJudgeCommand::INPUT, fn (): string => $input);

    expect(Artisan::call('agent:judge', ['event' => $event]))->toBe(0);

    return Artisan::output();
}

it('prints the block decision for the stop event', function (): void {
    config(['agent-tools.stop.mode' => 'enforce']);
    Http::fake(['*' => Http::response(['answers' => ['claims_done' => ['type' => 'noul', 'noul' => 0.9]]])]);

    $output = judge('stop', json_encode([
        'transcript_path' => transcript([['Edit', ['file_path' => '/app/Order.php']]]),
        'stop_hook_active' => false,
        'last_assistant_message' => 'All done.',
    ]));

    expect(json_decode($output, true)['decision'] ?? null)->toBe('block');
});

it('prints nothing and succeeds on input it cannot use', function (): void {
    expect(judge('stop', 'not json'))->toBe('')
        ->and(judge('unknown-event', '{}'))->toBe('');
});
