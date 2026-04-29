<?php

declare(strict_types=1);

use App\Models\NewsletterRequest;

test('resend webhook records rejected event for bounced emails', function () {
    config()->set('services.resend.webhook_secret', resendWebhookSecret());
    config()->set('services.resend.key', 'test-resend-key');

    $delivery = NewsletterRequest::query()->create([
        'original_request' => ['domain' => 'example.com'],
    ])->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ])->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'provider_message_id' => 're_123',
        'recipient' => 'person@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
    ]);

    $payload = [
        'type' => 'email.bounced',
        'created_at' => now()->toISOString(),
        'data' => [
            'email_id' => 're_123',
        ],
    ];

    $this->withHeaders(resendWebhookHeaders($payload))
        ->postJson(route('webhooks.resend'), $payload)
        ->assertSuccessful()
        ->assertJson(['ok' => true]);

    $delivery->refresh();

    expect($delivery->latest_event)->toBe('rejected')
        ->and($delivery->events)->toHaveCount(1)
        ->and($delivery->events->sole()->provider_event)->toBe('email.bounced');
});

test('resend webhook ignores duplicate svix deliveries', function () {
    config()->set('services.resend.webhook_secret', resendWebhookSecret());
    config()->set('services.resend.key', 'test-resend-key');

    $delivery = NewsletterRequest::query()->create([
        'original_request' => ['domain' => 'example.com'],
    ])->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ])->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'provider_message_id' => 're_123',
        'recipient' => 'person@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
    ]);

    $payload = [
        'type' => 'email.delivered',
        'created_at' => now()->toISOString(),
        'data' => [
            'email_id' => 're_123',
        ],
    ];

    $headers = resendWebhookHeaders($payload, 'msg_duplicate');

    $this->withHeaders($headers)->postJson(route('webhooks.resend'), $payload)->assertSuccessful();
    $this->withHeaders($headers)->postJson(route('webhooks.resend'), $payload)->assertSuccessful();

    $delivery->refresh();

    expect($delivery->events)->toHaveCount(1)
        ->and($delivery->events->sole()->provider_event_id)->toBe('msg_duplicate');
});

test('resend webhook ignores email sent events because message sent records accepted state', function () {
    config()->set('services.resend.webhook_secret', resendWebhookSecret());
    config()->set('services.resend.key', 'test-resend-key');

    $delivery = NewsletterRequest::query()->create([
        'original_request' => ['domain' => 'example.com'],
    ])->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ])->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'provider_message_id' => 're_123',
        'recipient' => 'person@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
    ]);

    $payload = [
        'type' => 'email.sent',
        'created_at' => now()->toISOString(),
        'data' => [
            'email_id' => 're_123',
        ],
    ];

    $this->withHeaders(resendWebhookHeaders($payload))
        ->postJson(route('webhooks.resend'), $payload)
        ->assertSuccessful();

    $delivery->refresh();

    expect($delivery->events)->toBeEmpty();
});

test('resend webhook records temporary failed event for delayed deliveries', function () {
    config()->set('services.resend.webhook_secret', resendWebhookSecret());
    config()->set('services.resend.key', 'test-resend-key');

    $delivery = NewsletterRequest::query()->create([
        'original_request' => ['domain' => 'example.com'],
    ])->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ])->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'provider_message_id' => 're_456',
        'recipient' => 'person@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
    ]);

    $payload = [
        'type' => 'email.delivery_delayed',
        'created_at' => now()->toISOString(),
        'data' => [
            'email_id' => 're_456',
        ],
    ];

    $this->withHeaders(resendWebhookHeaders($payload))
        ->postJson(route('webhooks.resend'), $payload)
        ->assertSuccessful();

    $delivery->refresh();

    expect($delivery->latest_event)->toBe('failed')
        ->and($delivery->latest_severity)->toBe('temporary')
        ->and($delivery->events->sole()->severity)->toBe('temporary');
});

function resendWebhookSecret(): string
{
    return 'whsec_'.base64_encode('test-secret');
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, string>
 */
function resendWebhookHeaders(array $payload, string $messageId = 'msg_123'): array
{
    $timestamp = (string) time();
    $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = base64_encode(pack('H*', hash_hmac('sha256', "{$messageId}.{$timestamp}.{$jsonPayload}", 'test-secret')));

    return [
        'svix-id' => $messageId,
        'svix-timestamp' => $timestamp,
        'svix-signature' => 'v1,'.$signature,
    ];
}
