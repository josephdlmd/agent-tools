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
     * @param  array<string, mixed>|string  $state
     * @param  array<string, array<string, mixed>>  $questions
     * @return array<string, array<string, mixed>>|null
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
                ->retry(2, 200, fn (Throwable $exception): bool => $exception instanceof RequestException
                    && in_array($exception->response->status(), [429, 529], true), throw: false)
                ->post((string) config('agent-tools.typesafe.url'), [
                    'state' => $state,
                    'model' => $model,
                    'questions' => $questions,
                ]);

            $answers = $response->successful() ? $response->json('answers') : null;

            if (! is_array($answers)) {
                return null;
            }

            Cache::put($cacheKey, $answers, (int) config('agent-tools.typesafe.cache_seconds'));

            return $answers;
        } catch (Throwable) {
            return null;
        }
    }
}
