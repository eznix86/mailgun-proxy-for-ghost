<?php

declare(strict_types=1);

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

    'mailgun' => [
        'key' => env('MAILGUN_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
        'base_url' => env('RESEND_BASE_URL', 'https://api.resend.com'),
    ],

    'outbox' => [
        'provider' => env('OUTBOX_PROVIDER', env('MAIL_MAILER', 'mailbox')),

        'resend' => [
            // Batch sending is on by default for the Resend provider. Set
            // OUTBOX_RESEND_BATCH=false to fall back to the per-recipient
            // mailable flow (one queued email per subscriber).
            'batch' => env('OUTBOX_RESEND_BATCH', true),

            // Resend caps a batch call at 100 emails. Kept configurable so it
            // can only ever be lowered, never raised past the hard limit.
            'batch_size' => (int) env('OUTBOX_RESEND_BATCH_SIZE', 100),

            // Pause between batch calls (milliseconds). 200ms keeps us at
            // ~5 calls/second — well under Resend's 10 req/s team limit.
            'batch_pause_ms' => (int) env('OUTBOX_RESEND_BATCH_PAUSE_MS', 200),
        ],
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

];
