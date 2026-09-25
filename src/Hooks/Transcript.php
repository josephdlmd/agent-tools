<?php

namespace Josephdlmd\AgentTools\Hooks;

/**
 * Reads the current turn of a Claude Code transcript: the tool calls made since
 * the person's last prompt, in order.
 */
class Transcript
{
    /**
     * @param  list<array{name: string, input: array<string, mixed>}>  $toolCalls
     */
    public function __construct(public readonly array $toolCalls) {}

    public static function currentTurn(?string $path): ?self
    {
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $toolCalls = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);

            if (! is_array($entry) || ($entry['isSidechain'] ?? false) === true) {
                continue;
            }

            if (self::isPrompt($entry)) {
                $toolCalls = [];

                continue;
            }

            if (($entry['type'] ?? null) !== 'assistant') {
                continue;
            }

            foreach ((array) ($entry['message']['content'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'tool_use') {
                    $toolCalls[] = [
                        'name' => (string) ($block['name'] ?? ''),
                        'input' => is_array($block['input'] ?? null) ? $block['input'] : [],
                    ];
                }
            }
        }

        return new self($toolCalls);
    }

    /**
     * The text of the assistant's last message in the transcript, or null.
     */
    public static function lastAssistantText(?string $path): ?string
    {
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $text = null;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);

            if (! is_array($entry) || ($entry['type'] ?? null) !== 'assistant' || ($entry['isSidechain'] ?? false) === true) {
                continue;
            }

            $blocks = array_filter((array) ($entry['message']['content'] ?? []), fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'text' && trim((string) ($block['text'] ?? '')) !== '');

            if ($blocks !== []) {
                $text = implode("\n", array_map(fn (array $block): string => (string) $block['text'], $blocks));
            }
        }

        return $text;
    }

    /**
     * A prompt is a user entry the person wrote: not injected, not a tool result.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function isPrompt(array $entry): bool
    {
        if (($entry['type'] ?? null) !== 'user' || ($entry['isMeta'] ?? false) === true) {
            return false;
        }

        $content = $entry['message']['content'] ?? null;

        if (is_string($content)) {
            return true;
        }

        if (! is_array($content) || $content === []) {
            return false;
        }

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_result') {
                return false;
            }
        }

        return true;
    }

    /**
     * Files matching the pattern edited by the given tools after the last
     * Bash command matching the check pattern, or all of them if it never ran.
     *
     * @param  list<string>  $editTools
     * @return list<string>
     */
    public function editsAfterLastRun(array $editTools, string $filePattern, string $checkPattern): array
    {
        $edited = [];

        foreach ($this->toolCalls as $call) {
            if ($call['name'] === 'Bash' && preg_match($checkPattern, (string) ($call['input']['command'] ?? '')) === 1) {
                $edited = [];

                continue;
            }

            $file = (string) ($call['input']['file_path'] ?? '');

            if (in_array($call['name'], $editTools, true) && preg_match($filePattern, $file) === 1) {
                $edited[$file] = true;
            }
        }

        return array_keys($edited);
    }
}
