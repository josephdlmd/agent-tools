<?php

use Josephdlmd\AgentTools\Hooks\Transcript;

it('reads only the tool calls since the last prompt', function (): void {
    $turn = Transcript::currentTurn(transcript([
        ['Edit', ['file_path' => '/app/Order.php']],
    ], withEarlierTurn: true));

    expect($turn->toolCalls)->toHaveCount(1)
        ->and($turn->toolCalls[0]['input']['file_path'])->toBe('/app/Order.php');
});

it('lists PHP edits made after the last matching command', function (): void {
    $turn = Transcript::currentTurn(transcript([
        ['Edit', ['file_path' => '/app/Order.php']],
        ['Bash', ['command' => 'vendor/bin/pint --dirty']],
        ['Write', ['file_path' => '/app/Invoice.php']],
        ['Edit', ['file_path' => '/resources/css/app.css']],
    ]));

    expect($turn->editsAfterLastRun(['Edit', 'Write'], '/\.php$/', '/\bpint\b/'))->toBe(['/app/Invoice.php'])
        ->and($turn->editsAfterLastRun(['Edit', 'Write'], '/\.php$/', '/\bpest\b/'))->toBe(['/app/Order.php', '/app/Invoice.php']);
});

it('returns null for a missing transcript', function (): void {
    expect(Transcript::currentTurn('/nowhere/transcript.jsonl'))->toBeNull();
});
