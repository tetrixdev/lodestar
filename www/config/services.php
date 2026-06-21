<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'github' => [
        // Token used server-side to read the compare API for review file lists.
        // v1: one server token; per-user "Connect GitHub" is a later task.
        'token' => env('GITHUB_TOKEN'),
    ],

    'openai' => [
        // Operator opt-in: the embeddings / semantic-search pipeline only runs
        // when a key is present. Absent or invalid, the pipeline fail-safes
        // (records the error, skips, never crashes the queue) and search
        // degrades to empty. Text leaves the box to OpenAI's embeddings API only
        // when this is set — see docs/ARCHITECTURE.md "Boundary: OpenAI".
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

];
