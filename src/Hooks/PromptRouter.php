<?php

namespace Josephdlmd\AgentTools\Hooks;

use Illuminate\Support\Facades\Log;
use Josephdlmd\AgentTools\TypeSafe\Judge;

/**
 * Suggests which skills and rule files the agent should load for a prompt.
 * Two TypeSafe requests, the shape of TypeSafe's skill-suggestion cookbook:
 * rank every candidate with gate questions, then re-check a shortlist. Code
 * then surfaces only the skills and confidence levels that scored well offline.
 */
class PromptRouter
{
    private const NONE = 'none';

    public function __construct(private readonly Judge $judge) {}

    /**
     * @param  array<string, mixed>  $input  The UserPromptSubmit hook's JSON input.
     * @return array<string, mixed>|null Hook output, or null to add nothing.
     */
    public function evaluate(array $input): ?array
    {
        $config = (array) config('agent-tools.prompt');
        $prompt = trim((string) ($input['prompt'] ?? ''));

        if (! filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOL) || $prompt === '' || str_starts_with($prompt, '/')) {
            return null;
        }

        $candidates = $this->candidates((string) $config['candidates']);

        if ($candidates === null) {
            return null;
        }

        $state = array_filter([
            'project' => (string) $config['project'],
            'request' => mb_substr($prompt, 0, 1500),
            'assistant_previous_message' => mb_substr((string) Transcript::lastAssistantText(isset($input['transcript_path']) ? (string) $input['transcript_path'] : null), -1500),
        ], fn (string $value): bool => $value !== '');

        $wide = $this->judge->ask($state, $this->wideQuestions($candidates));

        if ($wide === null) {
            return null;
        }

        $answers = $wide['answers'];
        $gates = [
            'skills' => ($this->noul($answers, 'gate::asks_for_work') + $this->noul($answers, 'gate::follows_documented_procedure')) / 2,
            'references' => $this->noul($answers, 'gate::touches_filament_screen'),
        ];

        $shortlists = [];

        foreach (['skills' => 'skill', 'references' => 'reference'] as $kind => $question) {
            $probabilities = (array) ($answers[$question]['probabilities'] ?? []);
            arsort($probabilities);
            $ranked = array_values(array_filter(array_keys($probabilities), fn (string $name): bool => $name !== self::NONE));
            $noneWins = array_key_first($probabilities) === self::NONE;
            $shortlists[$kind] = $gates[$kind] >= (float) $config['gate'] && ! $noneWins
                ? array_slice($ranked, 0, (int) $config['shortlist'])
                : [];
        }

        $check = array_filter($shortlists) === [] ? null : $this->judge->ask($state, $this->checkQuestions($shortlists, $candidates));
        $checkAnswers = $check['answers'] ?? [];

        $suggested = [];

        foreach (['skills', 'references'] as $kind) {
            $fits = [];

            foreach ($shortlists[$kind] as $name) {
                $fits[$name] = $this->noul($checkAnswers, "fits::{$kind}::{$name}");
            }

            $suggested[$kind] = [];

            if ($fits !== [] && max($fits) >= (float) $config['fits'] && isset($checkAnswers[$kind]['choice'])) {
                $extra = array_keys(array_filter($fits, fn (float $value): bool => $value >= (float) $config['multi']));
                $suggested[$kind] = array_values(array_unique([$checkAnswers[$kind]['choice'], ...$extra]));
            }
        }

        $referenceConfidence = (float) ($answers['reference']['confidence'] ?? 0);
        $surfaced = [
            'skills' => array_values(array_intersect($suggested['skills'], (array) $config['surface_skills'])),
            'references' => $referenceConfidence >= (float) $config['reference_confidence'] ? $suggested['references'] : [],
        ];

        $this->log([
            'event' => 'prompt',
            'session_id' => $input['session_id'] ?? null,
            'model' => $wide['model'],
            'input_tokens' => ($wide['input_tokens'] ?? 0) + ($check['input_tokens'] ?? 0),
            'gates' => $gates,
            'reference_confidence' => $referenceConfidence,
            'suggested' => $suggested,
            'surfaced' => $surfaced,
            'mode' => $config['mode'],
            'prompt' => mb_substr($prompt, 0, 300),
        ]);

        if ($config['mode'] !== 'suggest' || array_filter($surfaced) === []) {
            return null;
        }

        return [
            'hookSpecificOutput' => [
                'hookEventName' => 'UserPromptSubmit',
                'additionalContext' => $this->hint($surfaced),
            ],
        ];
    }

    /**
     * @param  array{skills: array<string, array{short: string, full: string}>, references: array<string, array{short: string, full: string}>}  $candidates
     * @return array<string, array<string, mixed>>
     */
    private function wideQuestions(array $candidates): array
    {
        $skills = array_map(fn (array $skill): string => $skill['short'], $candidates['skills']);
        $skills[self::NONE] = 'No skill applies: an answer to the assistant\'s questions, an approval, a quick fact, or anything general knowledge covers.';
        $references = array_map(fn (array $reference): string => $reference['short'], $candidates['references']);
        $references[self::NONE] = 'No UX reference applies: the request does not change or review how a Filament screen looks, reads or behaves.';

        return [
            'skill' => [
                'type' => 'choice',
                'instructions' => 'Which of these skills, if any, is the right one for the assistant to load before handling the `request`?',
                'criteria' => $skills,
            ],
            'reference' => [
                'type' => 'choice',
                'instructions' => 'Which of these Filament UX rule files, if any, should the assistant read before handling the `request`?',
                'criteria' => $references,
            ],
            'gate::asks_for_work' => [
                'type' => 'noul',
                'instructions' => 'Does the `request` ask the assistant to do new work in the project?',
                'criteria' => [
                    'true' => 'It asks to build, change, run, review, plan or investigate something.',
                    'false' => 'It only acknowledges, approves, picks one of the options the assistant offered, or asks a quick factual question.',
                ],
            ],
            'gate::follows_documented_procedure' => [
                'type' => 'noul',
                'instructions' => 'Would a careful engineer on this project follow a documented workflow before doing what the `request` asks?',
            ],
            'gate::touches_filament_screen' => [
                'type' => 'noul',
                'instructions' => 'Does the `request` involve how a Filament screen looks or behaves for its users?',
                'criteria' => [
                    'true' => 'It changes or reviews what people see or do on a screen, such as its layout, tables, filters, forms, wording, colours, figures, dashboards or sign-in.',
                    'false' => 'It is about code, data, tooling or process with no effect on what people see or do on a screen.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, list<string>>  $shortlists
     * @param  array<string, array<string, array{short: string, full: string}>>  $candidates
     * @return array<string, array<string, mixed>>
     */
    private function checkQuestions(array $shortlists, array $candidates): array
    {
        $questions = [];

        foreach ($shortlists as $kind => $names) {
            if ($names === []) {
                continue;
            }

            $noun = $kind === 'skills' ? 'skill' : 'Filament UX rule file';
            $questions[$kind] = [
                'type' => 'choice',
                'instructions' => "Which of these {$noun}s best fits what the `request` asks for? Read what each covers, not just its name.",
                'criteria' => array_combine($names, array_map(fn (string $name): string => $candidates[$kind][$name]['full'], $names)),
            ];

            foreach ($names as $name) {
                $questions["fits::{$kind}::{$name}"] = [
                    'type' => 'noul',
                    'instructions' => [
                        'candidate' => ['name' => $name, 'covers' => $candidates[$kind][$name]['short']],
                        'question' => "Does the {$noun} `candidate` cover the specific thing the `request` asks for?",
                    ],
                ];
            }
        }

        return $questions;
    }

    /**
     * @return array{skills: array<string, array{short: string, full: string}>, references: array<string, array{short: string, full: string}>}|null
     */
    private function candidates(string $path): ?array
    {
        $candidates = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($candidates) || ! is_array($candidates['skills'] ?? null) || ! is_array($candidates['references'] ?? null)) {
            return null;
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function noul(array $answers, string $key): float
    {
        return (float) ($answers[$key]['noul'] ?? 0);
    }

    /**
     * @param  array{skills: list<string>, references: list<string>}  $surfaced
     */
    private function hint(array $surfaced): string
    {
        $parts = [];

        if ($surfaced['skills'] !== []) {
            $parts[] = 'load the '.implode(', ', $surfaced['skills']).' skill'.(count($surfaced['skills']) > 1 ? 's' : '');
        }

        if ($surfaced['references'] !== []) {
            $parts[] = 'read '.implode(', ', $surfaced['references']);
        }

        return 'Suggested for this request (automatic, check before relying on it): '.implode('; ', $parts).'.';
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function log(array $entry): void
    {
        rescue(fn () => Log::build([
            'driver' => 'single',
            'path' => config('agent-tools.log_path'),
        ])->info('judgment', $entry), report: false);
    }
}
