<?php

namespace Josephdlmd\AgentTools\Hooks;

use Illuminate\Support\Facades\Log;
use Josephdlmd\AgentTools\TypeSafe\Judge;

/**
 * Blocks a Stop when the turn changed PHP files without running the formatter
 * or tests afterwards and the final message claims the work is done. Code finds
 * the missing runs; TypeSafe only judges the claim.
 */
class StopGuard
{
    public function __construct(private readonly Judge $judge) {}

    /**
     * @param  array<string, mixed>  $input  The Stop hook's JSON input.
     * @return array<string, mixed>|null Hook output, or null to let the turn end.
     */
    public function evaluate(array $input): ?array
    {
        $config = (array) config('agent-tools.stop');

        if (! filter_var($config['enabled'] ?? true, FILTER_VALIDATE_BOOL) || ($input['stop_hook_active'] ?? false) === true) {
            return null;
        }

        $message = trim((string) ($input['last_assistant_message'] ?? ''));
        $turn = Transcript::currentTurn(isset($input['transcript_path']) ? (string) $input['transcript_path'] : null);

        if ($message === '' || $turn === null) {
            return null;
        }

        $unformatted = $turn->editsAfterLastRun($config['edit_tools'], $config['edited_file_pattern'], $config['format_pattern']);
        $untested = $turn->editsAfterLastRun($config['edit_tools'], $config['edited_file_pattern'], $config['test_pattern']);

        if ($unformatted === [] && $untested === []) {
            return null;
        }

        $judgment = $this->judge->ask(['message' => $this->excerpt($message)], ['claims_done' => $config['question']]);
        $claimsDone = $judgment['answers']['claims_done']['noul'] ?? null;

        if (! is_numeric($claimsDone)) {
            return null;
        }

        $wouldBlock = (float) $claimsDone >= (float) $config['threshold'];
        $enforcing = $config['mode'] === 'enforce';

        $this->log([
            'event' => 'stop',
            'session_id' => $input['session_id'] ?? null,
            'model' => $judgment['model'] ?? null,
            'input_tokens' => $judgment['input_tokens'] ?? null,
            'claims_done' => (float) $claimsDone,
            'threshold' => (float) $config['threshold'],
            'unformatted' => $unformatted,
            'untested' => $untested,
            'would_block' => $wouldBlock,
            'blocked' => $wouldBlock && $enforcing,
            'message' => mb_substr($message, 0, 500),
        ]);

        if (! $wouldBlock || ! $enforcing) {
            return null;
        }

        return [
            'decision' => 'block',
            'reason' => $this->reason($config, $unformatted, $untested),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $unformatted
     * @param  list<string>  $untested
     */
    private function reason(array $config, array $unformatted, array $untested): string
    {
        $steps = [];

        if ($unformatted !== []) {
            $steps[] = "run `{$config['format_command']}`";
        }

        if ($untested !== []) {
            $steps[] = "run {$config['test_command']}";
        }

        $files = implode(', ', array_map('basename', array_unique([...$unformatted, ...$untested])));

        return 'You changed PHP files this turn ('.$files.') without checking them afterwards. Before reporting the work as done, '
            .implode(' and ', $steps).', then report the result.';
    }

    /**
     * The start and end of a long message, where a claim of being done sits;
     * the middle is detail that only lowers accuracy (docs: jaggedness 5).
     */
    private function excerpt(string $message): string
    {
        $limit = 1000;

        if (mb_strlen($message) <= $limit * 2) {
            return $message;
        }

        return mb_substr($message, 0, $limit)."\n…\n".mb_substr($message, -$limit);
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
