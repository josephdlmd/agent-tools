<?php

namespace Josephdlmd\AgentTools\TypeSafe;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks TypeSafe typed questions about a state. Every failure returns null so
 * callers fail open: a missing key, a timeout or an error never blocks work.
 */
class Judge
{
    /**
     * Statuses TypeSafe asks callers to retry with backoff (docs: /api#handling-rate-limits).
     */
    private const RETRYABLE = [429, 529];

    /**
     * @param  array<string, mixed>|string  $state
     * @param  array<string, array<string, mixed>>  $questions
     * @return array{answers: array<string, array<string, mixed>>, model: string, input_tokens: int|null}|null
     */
    public function ask(array|string $state, array $questions): ?array
    {
        $key = config('agent-tools.typesafe.key');

        if (blank($key)) {
            return null;
        }

        $model = (string) config('agent-tools.typesafe.model');
        $cacheKey = 'agent-tools:typesafe:'.hash('sha256', $model.json_encode($state).json_encode($questions));

        try {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }

            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout((int) config('agent-tools.typesafe.timeout'))
                ->retry(3, fn (int $attempt, Throwable $exception): int => $this->backoff($attempt, $exception),
                    fn (Throwable $exception): bool => $exception instanceof RequestException
                        && in_array($exception->response->status(), self::RETRYABLE, true), throw: false)
                ->post((string) config('agent-tools.typesafe.url'), [
                    'state' => $state,
                    'model' => $model,
                    'questions' => $questions,
                ]);

            $answers = $response->successful() ? $response->json('answers') : null;

            if (! is_array($answers)) {
                return null;
            }

            $result = [
                'answers' => $answers,
                'model' => (string) ($response->json('model') ?? $model),
                'input_tokens' => is_int($response->json('usage.input_tokens')) ? $response->json('usage.input_tokens') : null,
            ];

            Cache::put($cacheKey, $result, (int) config('agent-tools.typesafe.cache_seconds'));

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Milliseconds to wait before the next attempt: the response's retry-after
     * when it sends one, otherwise exponential (250, 1000, 4000).
     */
    private function backoff(int $attempt, Throwable $exception): int
    {
        $retryAfter = $exception instanceof RequestException ? $exception->response->header('Retry-After') : '';

        if (is_numeric($retryAfter)) {
            return min((int) ((float) $retryAfter * 1000), 10000);
        }

        return 250 * (4 ** ($attempt - 1));
    }
}
