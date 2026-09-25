<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TypeSafe
    |--------------------------------------------------------------------------
    |
    | The model is pinned, because thresholds tuned on one version do not carry
    | over to the next. Without a key every judgment is skipped and every hook
    | lets the agent carry on.
    |
    */

    'typesafe' => [
        'key' => env('TYPESAFE_API_KEY'),
        'url' => env('TYPESAFE_URL', 'https://api.typesafe.ai/v1/systemone'),
        'model' => env('TYPESAFE_MODEL', 'jev-1.13.0'),
        'timeout' => 8,
        'cache_seconds' => 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Judgment log
    |--------------------------------------------------------------------------
    |
    | Every judgment is logged with its decision, so thresholds can be tuned
    | from real outcomes before a hook is allowed to block.
    |
    */

    'log_path' => storage_path('logs/agent-judgments.log'),

    /*
    |--------------------------------------------------------------------------
    | Stop guard
    |--------------------------------------------------------------------------
    |
    | Stops the agent from ending a turn that claims the work is done when PHP
    | files changed in that turn and the formatter or tests were not run after
    | the last change. Code finds the missing runs; TypeSafe only judges whether
    | the final message claims the work is done. "shadow" logs what it would do;
    | "enforce" blocks.
    |
    */

    'stop' => [
        'enabled' => env('AGENT_TOOLS_STOP_ENABLED', true),
        'mode' => env('AGENT_TOOLS_STOP_MODE', 'shadow'),
        'threshold' => 0.7,
        'edit_tools' => ['Edit', 'Write', 'MultiEdit'],
        'edited_file_pattern' => '/\.php$/',
        'format_pattern' => '/\bpint\b/',
        'test_pattern' => '/\b(pest|artisan\s+test)\b/',
        'format_command' => 'vendor/bin/pint --dirty --format agent',
        'test_command' => 'php artisan test --compact with the affected test files',
        'question' => [
            'type' => 'noul',
            'instructions' => 'Does `message` report that the work is complete?',
            'criteria' => [
                'true' => 'It says the work is done, for example "Done", "I\'ve added the column and updated the form", or a summary of changes it made.',
                'false' => 'It asks a question, reports a blocker or failure, proposes a plan, or says work is still in progress.',
            ],
        ],
    ],

];
