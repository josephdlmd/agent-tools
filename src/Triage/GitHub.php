<?php

namespace Josephdlmd\AgentTools\Triage;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The few GitHub reads and writes triage needs, through the `gh` CLI so it uses
 * the developer's existing login.
 */
class GitHub
{
    /**
     * @return list<array{number: int, title: string, body: string, labels: list<string>, assignees: list<string>, url: string}>
     */
    public function openIssues(int $limit): array
    {
        $issues = $this->json(['gh', 'issue', 'list', '--state', 'open', '--limit', (string) $limit, '--json', 'number,title,body,labels,assignees,url']);

        return array_map(fn (array $issue): array => [
            'number' => (int) $issue['number'],
            'title' => (string) $issue['title'],
            'body' => (string) ($issue['body'] ?? ''),
            'labels' => array_map(fn (array $label): string => (string) $label['name'], (array) ($issue['labels'] ?? [])),
            'assignees' => array_map(fn (array $user): string => (string) $user['login'], (array) ($issue['assignees'] ?? [])),
            'url' => (string) ($issue['url'] ?? ''),
        ], $issues);
    }

    /**
     * Open issues blocking this one, from GitHub's native issue dependencies.
     */
    public function openBlockers(int $number): int
    {
        $issue = $this->json(['gh', 'api', "repos/{owner}/{repo}/issues/{$number}"]);

        return (int) ($issue['issue_dependencies_summary']['blocked_by'] ?? 0);
    }

    /**
     * Whether a comment containing the marker is already on the issue.
     */
    public function hasCommentWith(int $number, string $marker): bool
    {
        $issue = $this->json(['gh', 'issue', 'view', (string) $number, '--json', 'comments']);

        foreach ((array) ($issue['comments'] ?? []) as $comment) {
            if (str_contains((string) ($comment['body'] ?? ''), $marker)) {
                return true;
            }
        }

        return false;
    }

    public function comment(int $number, string $body): void
    {
        $result = Process::input($body)->run(['gh', 'issue', 'comment', (string) $number, '--body-file', '-']);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput()));
        }
    }

    /**
     * @param  list<string>  $command
     * @return array<mixed>
     */
    private function json(array $command): array
    {
        $result = Process::run($command);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'gh failed');
        }

        $decoded = json_decode($result->output(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
