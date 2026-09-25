<?php

use Illuminate\Support\Facades\Http;
use Josephdlmd\AgentTools\Hooks\StopGuard;

function stopInput(string $transcript, array $overrides = []): array
{
    return [
        'session_id' => 'abc',
        'transcript_path' => $transcript,
        'stop_hook_active' => false,
        'last_assistant_message' => "Done: I've added the column and updated the form.",
        ...$overrides,
    ];
}

function fakeClaimsDone(float $probability): void
{
    Http::fake(['*' => Http::response([
        'model' => 'jev-1.13.0',
        'answers' => ['claims_done' => ['type' => 'noul', 'noul' => $probability]],
    ])]);
}

it('blocks a claimed-done turn with unchecked PHP edits when enforcing', function (): void {
    config(['agent-tools.stop.mode' => 'enforce']);
    fakeClaimsDone(0.93);

    $output = app(StopGuard::class)->evaluate(stopInput(transcript([
        ['Edit', ['file_path' => '/app/Order.php']],
    ])));

    expect($output['decision'])->toBe('block')
        ->and($output['reason'])->toContain('Order.php')->toContain('pint')->toContain('php artisan test');
    Http::assertSentCount(1);
});

it('only logs in shadow mode', function (): void {
    fakeClaimsDone(0.93);

    expect(app(StopGuard::class)->evaluate(stopInput(transcript([
        ['Edit', ['file_path' => '/app/Order.php']],
    ]))))->toBeNull();
    Http::assertSentCount(1);
});

it('lets the turn end when the message does not claim the work is done', function (): void {
    config(['agent-tools.stop.mode' => 'enforce']);
    fakeClaimsDone(0.12);

    expect(app(StopGuard::class)->evaluate(stopInput(transcript([
        ['Edit', ['file_path' => '/app/Order.php']],
    ]))))->toBeNull();
});

it('asks nothing when the formatter and tests ran after the last edit', function (): void {
    Http::fake();

    expect(app(StopGuard::class)->evaluate(stopInput(transcript([
        ['Edit', ['file_path' => '/app/Order.php']],
        ['Bash', ['command' => 'vendor/bin/pint --dirty --format agent']],
        ['Bash', ['command' => 'php artisan test --compact tests/Feature/OrderTest.php']],
    ]))))->toBeNull();
    Http::assertNothingSent();
});

it('never blocks twice in a row', function (): void {
    config(['agent-tools.stop.mode' => 'enforce']);
    Http::fake();

    expect(app(StopGuard::class)->evaluate(stopInput(transcript([
        ['Edit', ['file_path' => '/app/Order.php']],
    ]), ['stop_hook_active' => true])))->toBeNull();
    Http::assertNothingSent();
});

it('fails open when TypeSafe errors or has no key', function (): void {
    config(['agent-tools.stop.mode' => 'enforce']);
    Http::fake(['*' => Http::response(['error' => 'down'], 500)]);
    $input = stopInput(transcript([['Edit', ['file_path' => '/app/Order.php']]]));

    expect(app(StopGuard::class)->evaluate($input))->toBeNull();

    config(['agent-tools.typesafe.key' => null]);
    expect(app(StopGuard::class)->evaluate($input))->toBeNull();
});

it('sends only the start and end of a long message', function (): void {
    fakeClaimsDone(0.9);
    $long = 'Done. '.str_repeat('Detail about the change. ', 400).'All tests pass.';

    app(StopGuard::class)->evaluate(stopInput(transcript([
        ['Edit', ['file_path' => '/app/Order.php']],
    ]), ['last_assistant_message' => $long]));

    Http::assertSent(function ($request): bool {
        $sent = $request['state']['message'];

        return mb_strlen($sent) < 2100 && str_starts_with($sent, 'Done.') && str_ends_with($sent, 'All tests pass.');
    });
});
