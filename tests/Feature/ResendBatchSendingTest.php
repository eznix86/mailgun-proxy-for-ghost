<?php

declare(strict_types=1);

use App\Events\NewsletterRequested;
use App\Listeners\ProcessNewsletterRequest;
use App\Mail\GhostNewsletter;
use App\Models\NewsletterRequest;
use App\Models\NewsletterRequestDelivery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    config()->set('services.outbox.provider', 'resend');
    config()->set('services.outbox.resend.batch', true);
    config()->set('services.outbox.resend.batch_pause_ms', 0);
    config()->set('services.resend.key', 'test-resend-key');
    config()->set('services.resend.base_url', 'https://api.resend.com');
});

test('resend batch path chunks recipients into calls of at most 100 emails', function (): void {
    fakeResendBatchSuccess();

    $recipients = collect(range(1, 250))
        ->map(fn (int $index): string => "user{$index}@example.com")
        ->all();

    $newsletterRequest = makeBatchNewsletterRequest($recipients);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    Http::assertSentCount(3);

    $sizes = [];
    Http::assertSent(function (Request $request) use (&$sizes): bool {
        $sizes[] = count((array) $request->data());

        return true;
    });

    expect($sizes)->toBe([100, 100, 50])
        ->and(NewsletterRequestDelivery::query()->count())->toBe(250);
});

test('each batch payload carries only its own recipient\'s resolved content', function (): void {
    fakeResendBatchSuccess();

    $newsletterRequest = makeBatchNewsletterRequest(['ann@example.com', 'bob@example.com']);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    $payload = capturedBatchPayload();

    expect($payload[0]['to'])->toBe(['ann@example.com'])
        ->and($payload[0]['subject'])->toBe('Hello ann@example.com')
        ->and($payload[0]['text'])->toBe('Hi ann@example.com')
        ->and($payload[0]['html'])->toBe('<p>Hi ann@example.com</p>')
        ->and($payload[1]['to'])->toBe(['bob@example.com'])
        ->and($payload[1]['subject'])->toBe('Hello bob@example.com')
        ->and($payload[1]['text'])->toBe('Hi bob@example.com')
        ->and($payload[1]['html'])->toBe('<p>Hi bob@example.com</p>');
});

test('batch calls send a deterministic idempotency key that differs per chunk', function (): void {
    fakeResendBatchSuccess();

    $recipients = collect(range(1, 150))
        ->map(fn (int $index): string => "user{$index}@example.com")
        ->all();

    $newsletterRequest = makeBatchNewsletterRequest($recipients);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    $keys = [];
    Http::assertSent(function (Request $request) use (&$keys): bool {
        $keys[] = $request->header('Idempotency-Key')[0] ?? null;

        return true;
    });

    expect($keys)->toBe([
        "mgw-batch-{$newsletterRequest->id}-0",
        "mgw-batch-{$newsletterRequest->id}-1",
    ]);
});

test('records the provider message id per recipient from the batch response order', function (): void {
    fakeResendBatchSuccess();

    $newsletterRequest = makeBatchNewsletterRequest(['ann@example.com', 'bob@example.com', 'cat@example.com']);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    $deliveries = NewsletterRequestDelivery::query()->orderBy('id')->get();

    expect($deliveries->pluck('provider_message_id', 'recipient')->all())->toBe([
        'ann@example.com' => 'rid-ann@example.com',
        'bob@example.com' => 'rid-bob@example.com',
        'cat@example.com' => 'rid-cat@example.com',
    ])->and($deliveries->pluck('latest_event')->unique()->values()->all())->toBe(['accepted']);
});

test('resend webhook resolves a delivery against a batch-stored provider message id', function (): void {
    fakeResendBatchSuccess();
    config()->set('services.resend.webhook_secret', resendWebhookSecret());

    $newsletterRequest = makeBatchNewsletterRequest(['ann@example.com']);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    $delivery = NewsletterRequestDelivery::query()->sole();
    expect($delivery->provider_message_id)->toBe('rid-ann@example.com');

    $payload = [
        'type' => 'email.delivered',
        'created_at' => now()->toISOString(),
        'data' => [
            'email_id' => 'rid-ann@example.com',
        ],
    ];

    $this->withHeaders(resendWebhookHeaders($payload))
        ->postJson(route('webhooks.resend'), $payload)
        ->assertSuccessful();

    $delivery->refresh();

    expect($delivery->latest_event)->toBe('delivered');
});

test('maps o:deliverytime to a scheduled_at field on each batch payload', function (): void {
    fakeResendBatchSuccess();

    $deliveryTime = CarbonImmutable::parse('Tue, 28 Apr 2026 12:00:00 UTC');

    $newsletterRequest = makeBatchNewsletterRequest(['ann@example.com'], [
        'o:deliverytime' => $deliveryTime->toRfc7231String(),
    ]);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    $payload = capturedBatchPayload();

    expect($payload[0]['scheduled_at'])->toBe($deliveryTime->toIso8601String());
});

test('batch payload carries reply-to and custom headers but never attachments', function (): void {
    fakeResendBatchSuccess();

    $newsletterRequest = makeBatchNewsletterRequest(['ann@example.com'], [
        'h:Reply-To' => 'support@example.com',
        'h:List-Unsubscribe' => '<https://example.com/unsub>',
    ]);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    $payload = capturedBatchPayload();

    expect($payload[0]['reply_to'])->toBe('support@example.com')
        ->and($payload[0]['headers']['List-Unsubscribe'])->toBe('<https://example.com/unsub>')
        ->and($payload[0])->not->toHaveKey('attachments');
});

test('paces batch calls with a sleep between chunks to respect the rate limit', function (): void {
    Sleep::fake();
    fakeResendBatchSuccess();
    config()->set('services.outbox.resend.batch_pause_ms', 200);

    $recipients = collect(range(1, 150))
        ->map(fn (int $index): string => "user{$index}@example.com")
        ->all();

    $newsletterRequest = makeBatchNewsletterRequest($recipients);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    // Two chunks => exactly one 200ms pause between them.
    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 200);
});

test('a failed batch call marks the chunk deliveries failed and keeps a stable idempotency key across retries', function (): void {
    Http::fake([
        'api.resend.com/*' => Http::response(['message' => 'internal error'], 500),
    ]);

    $newsletterRequest = makeBatchNewsletterRequest(['ann@example.com', 'bob@example.com']);

    expect(fn () => resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest)))
        ->toThrow(RequestException::class);

    $deliveries = NewsletterRequestDelivery::query()->get();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('latest_event')->unique()->values()->all())->toBe(['failed']);

    // A retry creates a fresh attempt but must reuse the same idempotency key so
    // Resend deduplicates the chunk instead of double-sending it.
    expect(fn () => resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest)))
        ->toThrow(RequestException::class);

    $keys = [];
    Http::assertSent(function (Request $request) use (&$keys): bool {
        $keys[] = $request->header('Idempotency-Key')[0] ?? null;

        return true;
    });

    expect(array_unique($keys))->toBe(["mgw-batch-{$newsletterRequest->id}-0"]);
});

test('non-resend providers keep using the per-recipient mailable flow', function (): void {
    Mail::fake();
    Http::fake();
    config()->set('services.outbox.provider', 'array');

    $newsletterRequest = makeBatchNewsletterRequest(['ann@example.com', 'bob@example.com']);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    Http::assertNothingSent();
    Mail::assertQueued(GhostNewsletter::class, 2);
});

test('the escape hatch falls back to the per-recipient flow for resend', function (): void {
    Mail::fake();
    Http::fake();
    config()->set('services.outbox.provider', 'resend');
    config()->set('services.outbox.resend.batch', false);

    $newsletterRequest = makeBatchNewsletterRequest(['ann@example.com', 'bob@example.com']);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    Http::assertNothingSent();
    Mail::assertQueued(GhostNewsletter::class, 2);
});

/**
 * Fakes a successful Resend batch response, returning one id per payload in
 * request order. Each id is derived from the recipient so tests can assert the
 * response-order -> recipient mapping.
 */
function fakeResendBatchSuccess(): void
{
    Http::fake([
        'api.resend.com/*' => function (Request $request) {
            $emails = $request->data();

            $data = collect(is_array($emails) ? $emails : [])
                ->map(function (mixed $email): array {
                    $to = is_array($email) ? ($email['to'] ?? null) : null;
                    $recipient = is_array($to) ? ($to[0] ?? '') : $to;

                    return ['id' => 'rid-'.(is_string($recipient) ? $recipient : '')];
                })
                ->all();

            return Http::response(['data' => $data]);
        },
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function capturedBatchPayload(): array
{
    $payload = [];

    Http::assertSent(function (Request $request) use (&$payload): bool {
        $payload = (array) $request->data();

        return true;
    });

    return $payload;
}

/**
 * @param  array<int, string>  $recipients
 * @param  array<string, mixed>  $extraInput
 */
function makeBatchNewsletterRequest(array $recipients, array $extraInput = []): NewsletterRequest
{
    $recipientVariables = collect($recipients)
        ->mapWithKeys(fn (string $email): array => [$email => ['name' => $email]])
        ->all();

    return NewsletterRequest::query()->create([
        'original_request' => [
            'provider' => 'mailgun',
            'route' => 'mailgun.messages',
            'url' => 'http://example.test/v3/example.com/messages',
            'path' => '/v3/example.com/messages',
            'method' => 'POST',
            'domain' => 'example.com',
            'headers' => [],
            'query' => [],
            'input' => array_merge([
                'from' => 'newsletter@example.com',
                'subject' => 'Hello %recipient.name%',
                'html' => '<p>Hi %recipient.name%</p>',
                'text' => 'Hi %recipient.name%',
                'v:email-id' => 'ghost-id-123',
                'o:tag' => ['bulk-email'],
                'recipient-variables' => json_encode($recipientVariables, JSON_THROW_ON_ERROR),
            ], $extraInput),
            'files' => [],
        ],
    ]);
}
