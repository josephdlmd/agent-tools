<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Josephdlmd\AgentTools\Hooks\PromptRouter;

beforeEach(function (): void {
    $path = tempnam(sys_get_temp_dir(), 'candidates');
    file_put_contents($path, json_encode([
        'skills' => [
            'filament-ux' => ['short' => 'UX rules for Filament screens', 'full' => 'UX rules for Filament screens, full'],
            'filament-development' => ['short' => 'Builds Filament interfaces', 'full' => 'Builds Filament interfaces, full'],
        ],
        'references' => [
            'filament-ux/inputs.md' => ['short' => 'Form fields', 'full' => 'Form fields, full'],
        ],
    ]));

    config([
        'agent-tools.prompt.enabled' => true,
        'agent-tools.prompt.candidates' => $path,
        'agent-tools.prompt.surface_skills' => ['filament-ux'],
    ]);
});

function fakeRouting(string $skill, float $referenceConfidence = 0.9): void
{
    Http::fake(['*' => Http::sequence()
        ->push(['model' => 'jev-1.13.0', 'usage' => ['input_tokens' => 2000], 'answers' => [
            'skill' => ['type' => 'choice', 'choice' => $skill, 'confidence' => 0.8, 'probabilities' => [$skill => 0.8, 'none' => 0.2]],
            'reference' => ['type' => 'choice', 'choice' => 'filament-ux/inputs.md', 'confidence' => $referenceConfidence, 'probabilities' => ['filament-ux/inputs.md' => 0.9, 'none' => 0.1]],
            'gate::asks_for_work' => ['type' => 'noul', 'noul' => 0.9],
            'gate::follows_documented_procedure' => ['type' => 'noul', 'noul' => 0.8],
            'gate::touches_filament_screen' => ['type' => 'noul', 'noul' => 0.9],
        ]])
        ->push(['model' => 'jev-1.13.0', 'usage' => ['input_tokens' => 1500], 'answers' => [
            'skills' => ['type' => 'choice', 'choice' => $skill, 'confidence' => 0.9],
            "fits::skills::{$skill}" => ['type' => 'noul', 'noul' => 0.9],
            'references' => ['type' => 'choice', 'choice' => 'filament-ux/inputs.md', 'confidence' => 0.9],
            'fits::references::filament-ux/inputs.md' => ['type' => 'noul', 'noul' => 0.9],
        ]])]);
}

it('suggests surfaced skills and confident rule files in suggest mode', function (): void {
    config(['agent-tools.prompt.mode' => 'suggest']);
    fakeRouting('filament-ux');

    $output = app(PromptRouter::class)->evaluate(['prompt' => 'make the offer form easier to fill in']);

    expect($output['hookSpecificOutput']['hookEventName'])->toBe('UserPromptSubmit')
        ->and($output['hookSpecificOutput']['additionalContext'])->toContain('filament-ux skill')->toContain('filament-ux/inputs.md');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['state']['request'] === 'make the offer form easier to fill in'
        && $request['model'] === 'jev-1.13.0');
});

it('only logs in shadow mode', function (): void {
    fakeRouting('filament-ux');

    expect(app(PromptRouter::class)->evaluate(['prompt' => 'make the offer form easier to fill in']))->toBeNull();
    Http::assertSentCount(2);
});

it('keeps skills that are not surfaced and unconfident rule files to itself', function (): void {
    config(['agent-tools.prompt.mode' => 'suggest']);
    fakeRouting('filament-development', referenceConfidence: 0.6);

    expect(app(PromptRouter::class)->evaluate(['prompt' => 'add a column to the offers table']))->toBeNull();
});

it('skips slash commands, empty prompts and a missing candidates file', function (): void {
    Http::fake();

    expect(app(PromptRouter::class)->evaluate(['prompt' => '/clear']))->toBeNull()
        ->and(app(PromptRouter::class)->evaluate(['prompt' => '  ']))->toBeNull();

    config(['agent-tools.prompt.candidates' => '/nowhere.json']);
    expect(app(PromptRouter::class)->evaluate(['prompt' => 'make the form easier']))->toBeNull();
    Http::assertNothingSent();
});

it('is off unless an app enables it', function (): void {
    config(['agent-tools.prompt.enabled' => false]);
    Http::fake();

    expect(app(PromptRouter::class)->evaluate(['prompt' => 'make the form easier']))->toBeNull();
    Http::assertNothingSent();
});
