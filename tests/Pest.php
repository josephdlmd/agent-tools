<?php

use Josephdlmd\AgentTools\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * Writes a Claude Code transcript with a prompt followed by the given tool calls.
 *
 * @param  list<array{0: string, 1: array<string, mixed>}>  $toolCalls
 */
function transcript(array $toolCalls, bool $withEarlierTurn = false): string
{
    $lines = [];

    if ($withEarlierTurn) {
        $lines[] = ['type' => 'user', 'message' => ['content' => 'Earlier request']];
        $lines[] = ['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => '/app/Old.php']],
        ]]];
    }

    $lines[] = ['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'Add the column']]]];
    $lines[] = ['type' => 'user', 'isMeta' => true, 'message' => ['content' => 'Injected skill text']];

    foreach ($toolCalls as [$name, $input]) {
        $lines[] = ['type' => 'assistant', 'message' => ['content' => [
            ['type' => 'tool_use', 'name' => $name, 'input' => $input],
        ]]];
        $lines[] = ['type' => 'user', 'message' => ['content' => [
            ['type' => 'tool_result', 'content' => 'ok'],
        ]]];
    }

    $path = tempnam(sys_get_temp_dir(), 'transcript');
    file_put_contents($path, implode("\n", array_map('json_encode', $lines))."\n");

    return $path;
}
