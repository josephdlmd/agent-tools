<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Josephdlmd\AgentTools\TypeSafe\Judge;

$question = ['claims_done' => ['type' => 'noul', 'instructions' => 'Does `message` report that the work is complete?']];

it('sends the documented request shape with the pinned model', function () use ($question): void {
    Http::fake(['*' => Http::response([
        'model' => 'jev-1.13.0',
        'answers' => ['claims_done' => ['type' => 'noul', 'noul' => 0.9]],
        'usage' => ['input_tokens' => 310, 'output_tokens' => 20],
    ])]);

    $result = app(Judge::class)->ask(['message' => 'Done.'], $question);

    expect($result)->toBe([
        'answers' => ['claims_done' => ['type' => 'noul', 'noul' => 0.9]],
        'model' => 'jev-1.13.0',
        'input_tokens' => 310,
    ]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.typesafe.ai/v1/systemone'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request['model'] === 'jev-1.13.0'
        && $request['state'] === ['message' => 'Done.']
        && $request['questions'] === $question);
});

it('retries 429 and 529 with backoff, honouring retry-after', function () use ($question): void {
    Sleep::fake();
    Http::fake(['*' => Http::sequence()
        ->push(['error' => 'rate limited'], 429, ['Retry-After' => '2'])
        ->push(['error' => 'overloaded'], 529)
        ->push(['model' => 'jev-1.13.0', 'answers' => ['claims_done' => ['type' => 'noul', 'noul' => 0.2]]])]);

    expect(app(Judge::class)->ask('Done.', $question)['answers']['claims_done']['noul'])->toBe(0.2);
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

it('does not retry other errors and caches successful answers', function () use ($question): void {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => 'invalid question'], 422)
        ->push(['model' => 'jev-1.13.0', 'answers' => ['claims_done' => ['type' => 'noul', 'noul' => 0.9]]])]);

    expect(app(Judge::class)->ask('Done.', $question))->toBeNull();
    Http::assertSentCount(1);

    app(Judge::class)->ask('Done.', $question);
    app(Judge::class)->ask('Done.', $question);
    Http::assertSentCount(2);
});
