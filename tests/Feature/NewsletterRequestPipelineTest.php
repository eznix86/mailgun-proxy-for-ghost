<?php

declare(strict_types=1);

use App\Contracts\OutboxProvider;
use App\Actions\Mailgun\NormalizeMailgunRequest;
use App\Events\NewsletterRequested;
use App\Listeners\ProcessNewsletterRequest;
use App\Mail\GhostNewsletter;
use App\Models\NewsletterRequest;
use App\Outbox\BuiltinProvider;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

test('mailgun messages route stores the original request and dispatches the newsletter requested event', function () {
    Event::fake();

    config()->set('services.mailgun.key', 'test-mailgun-key');

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->postJson(route('mailgun.messages', ['domain' => 'example.com']), [
        'to' => 'person@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
        'text' => 'Body',
    ])->assertSuccessful();

    $newsletterRequest = NewsletterRequest::query()->sole();

    expect($newsletterRequest->original_request['domain'])->toBe('example.com')
        ->and($newsletterRequest->status->value)->toBe('pending');

    Event::assertDispatched(NewsletterRequested::class, fn (NewsletterRequested $event) => $event->newsletterRequest->is($newsletterRequest));
});

test('outbox provider binding resolves builtin provider when configured for resend', function () {
    config()->set('services.outbox.provider', 'resend');

    expect(resolve(OutboxProvider::class))->toBeInstanceOf(BuiltinProvider::class);
});

test('outbox provider binding resolves builtin provider by default', function () {
    config()->set('services.outbox.provider', 'mailbox');

    expect(resolve(OutboxProvider::class))->toBeInstanceOf(BuiltinProvider::class);
});

test('ghost newsletter applies resend rate limiting middleware when resend is configured', function () {
    config()->set('services.outbox.provider', 'resend');

    $newsletterRequest = NewsletterRequest::query()->create([
        'original_request' => [
            'provider' => 'mailgun',
            'route' => 'mailgun.messages',
            'url' => 'http://example.test/v3/example.com/messages',
            'path' => '/v3/example.com/messages',
            'method' => 'POST',
            'domain' => 'example.com',
            'headers' => [],
            'query' => [],
            'input' => [
                'to' => 'person@example.com',
                'from' => 'newsletter@example.com',
                'subject' => 'Hello',
                'text' => 'Body',
            ],
            'files' => [],
        ],
    ]);

    $request = resolve(NormalizeMailgunRequest::class)
        ->handle($newsletterRequest->original_request);

    $mailable = new GhostNewsletter($request, $request->recipients[0], 1);

    expect($mailable->middleware())
        ->toHaveCount(1)
        ->and($mailable->middleware()[0])
        ->toBeInstanceOf(RateLimited::class);
});

test('process newsletter request listener sends a ghost newsletter and stores a successful attempt', function () {
    Mail::fake();

    $newsletterRequest = NewsletterRequest::query()->create([
        'original_request' => [
            'provider' => 'mailgun',
            'route' => 'mailgun.messages',
            'url' => 'http://example.test/v3/example.com/messages',
            'path' => '/v3/example.com/messages',
            'method' => 'POST',
            'domain' => 'example.com',
            'headers' => [],
            'query' => [],
            'input' => [
                'to' => 'person@example.com',
                'from' => 'newsletter@example.com',
                'subject' => 'Hello',
                'text' => 'Body',
                'v:email-id' => 'ghost-id-123',
                'o:tag' => ['ghost-email'],
                'o:tracking-opens' => true,
            ],
            'files' => [],
        ],
    ]);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    $newsletterRequest->load('latestAttempt');

    expect($newsletterRequest->attempts)->toHaveCount(1)
        ->and($newsletterRequest->status->value)->toBe('processed')
        ->and($newsletterRequest->latestAttempt?->error_message)->toBeNull()
        ->and($newsletterRequest->latestAttempt?->deliveries)->toHaveCount(1);

    Mail::assertQueued(GhostNewsletter::class, 1);
});

test('process newsletter request listener replaces recipient placeholders before queueing the mailable', function () {
    Mail::fake();

    $newsletterRequest = NewsletterRequest::query()->create([
        'original_request' => [
            'provider' => 'mailgun',
            'route' => 'mailgun.messages',
            'url' => 'http://example.test/v3/example.com/messages',
            'path' => '/v3/example.com/messages',
            'method' => 'POST',
            'domain' => 'example.com',
            'headers' => [],
            'query' => [],
            'input' => [
                'to' => 'person@example.com',
                'from' => 'newsletter@example.com',
                'subject' => 'Hello %recipient.name%',
                'text' => 'Unsubscribe: %recipient.unsubscribe_url%',
                'h:List-Unsubscribe' => '<%recipient.list_unsubscribe%>, <%tag_unsubscribe_email%>',
                'recipient-variables' => json_encode([
                    'person@example.com' => [
                        'name' => 'Bruno',
                        'unsubscribe_url' => 'https://example.com/unsubscribe/bruno',
                        'list_unsubscribe' => 'https://example.com/list-unsubscribe/bruno',
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            'files' => [],
        ],
    ]);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    Mail::assertQueued(GhostNewsletter::class, function (GhostNewsletter $mailable, array $queue = []): bool {
        return $mailable->request->message->subject === 'Hello Bruno'
            && $mailable->request->message->text === 'Unsubscribe: https://example.com/unsubscribe/bruno'
            && ($mailable->request->headers['list_unsubscribe'] ?? null) === '<https://example.com/list-unsubscribe/bruno>';
    });
});

test('process newsletter request listener schedules the mailable when delivery time is provided', function () {
    Mail::fake();

    $deliveryTime = CarbonImmutable::parse('Tue, 28 Apr 2026 12:00:00 UTC');

    $newsletterRequest = NewsletterRequest::query()->create([
        'original_request' => [
            'provider' => 'mailgun',
            'route' => 'mailgun.messages',
            'url' => 'http://example.test/v3/example.com/messages',
            'path' => '/v3/example.com/messages',
            'method' => 'POST',
            'domain' => 'example.com',
            'headers' => [],
            'query' => [],
            'input' => [
                'to' => 'person@example.com',
                'from' => 'newsletter@example.com',
                'subject' => 'Hello',
                'text' => 'Body',
                'o:deliverytime' => $deliveryTime->toRfc7231String(),
            ],
            'files' => [],
        ],
    ]);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    Mail::assertQueued(GhostNewsletter::class, function (GhostNewsletter $mailable, array $queue = []) use ($deliveryTime): bool {
        return $mailable->request->options->deliveryTime?->equalTo($deliveryTime) ?? false;
    });
});

test('process newsletter request listener creates one delivery per recipient', function () {
    Mail::fake();

    $newsletterRequest = NewsletterRequest::query()->create([
        'original_request' => [
            'provider' => 'mailgun',
            'route' => 'mailgun.messages',
            'url' => 'http://example.test/v3/example.com/messages',
            'path' => '/v3/example.com/messages',
            'method' => 'POST',
            'domain' => 'example.com',
            'headers' => [],
            'query' => [],
            'input' => [
                'to' => 'person@example.com',
                'from' => 'newsletter@example.com',
                'subject' => 'Hello',
                'text' => 'Body',
                'v:email-id' => 'ghost-id-123',
                'o:tag' => ['ghost-email'],
                'recipient-variables' => json_encode([
                    'person@example.com' => ['name' => 'Bruno'],
                    'second@example.com' => ['name' => 'Ada'],
                ], JSON_THROW_ON_ERROR),
            ],
            'files' => [],
        ],
    ]);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    $attempt = $newsletterRequest->attempts()->with('deliveries')->sole();

    expect($attempt->deliveries)
        ->toHaveCount(2)
        ->and($attempt->deliveries->pluck('recipient')->all())
        ->toBe(['person@example.com', 'second@example.com']);
});
