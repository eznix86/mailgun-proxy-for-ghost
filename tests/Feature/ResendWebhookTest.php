<?php

declare(strict_types=1);

use App\Models\NewsletterRequest;
use App\Models\NewsletterRequestAttempt;
use App\Models\NewsletterRequestDelivery;

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

test('resend webhook persists the bounce reason and keeps the internal rejected state', function () {
    config()->set('services.resend.webhook_secret', resendWebhookSecret());
    config()->set('services.resend.key', 'test-resend-key');

    $delivery = webhookDelivery('re_bounce');

    // Real Resend email.bounced webhook shape: data.bounce.{message, subType, type}.
    $payload = [
        'type' => 'email.bounced',
        'created_at' => now()->toISOString(),
        'data' => [
            'email_id' => 're_bounce',
            'bounce' => [
                'message' => "The recipient's email address is on the suppression list because it has a recent history of producing hard bounces.",
                'subType' => 'Suppressed',
                'type' => 'Permanent',
            ],
        ],
    ];

    $this->withHeaders(resendWebhookHeaders($payload, 'msg_bounce'))
        ->postJson(route('webhooks.resend'), $payload)
        ->assertSuccessful();

    $delivery->refresh();
    $event = $delivery->events()->sole();

    // Design B invariant: the stored event keeps the internal `rejected` name (dashboard/health/status rely on it)...
    expect($delivery->latest_event)->toBe('rejected')
        ->and($event->getAttribute('event'))->toBe('rejected')
        // ...and the honest Resend bounce message is normalized onto payload.reason for serving + dashboard grouping.
        ->and(data_get($event->getAttribute('payload'), 'reason'))->toBe("The recipient's email address is on the suppression list because it has a recent history of producing hard bounces.");
});

test('resend webhook persists the send-failure reason from data.failed.reason', function () {
    config()->set('services.resend.webhook_secret', resendWebhookSecret());
    config()->set('services.resend.key', 'test-resend-key');

    $delivery = webhookDelivery('re_failed');

    // Real Resend email.failed webhook shape: data.failed.reason.
    $payload = [
        'type' => 'email.failed',
        'created_at' => now()->toISOString(),
        'data' => [
            'email_id' => 're_failed',
            'failed' => ['reason' => 'reached_daily_quota'],
        ],
    ];

    $this->withHeaders(resendWebhookHeaders($payload, 'msg_failed'))
        ->postJson(route('webhooks.resend'), $payload)
        ->assertSuccessful();

    $delivery->refresh();
    $event = $delivery->events()->sole();

    expect($delivery->latest_event)->toBe('failed')
        ->and(data_get($event->getAttribute('payload'), 'reason'))->toBe('reached_daily_quota');
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

/**
 * Seed a delivery keyed by its Resend provider message id, mirroring the annotated
 * chained-create pattern used by GhostMailgunEventsContractTest::ghostEventsDelivery.
 */
function webhookDelivery(string $providerMessageId): NewsletterRequestDelivery
{
    /** @var NewsletterRequestAttempt $attempt */
    $attempt = NewsletterRequest::query()->create([
        'original_request' => ['domain' => 'example.com'],
    ])->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    /** @var NewsletterRequestDelivery $delivery */
    $delivery = $attempt->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'provider_message_id' => $providerMessageId,
        'recipient' => 'person@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
    ]);

    return $delivery;
}
