<?php

declare(strict_types=1);

use App\Listeners\RecordAcceptedNewsletterDelivery;
use App\Models\NewsletterRequest;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

test('message sent listener records an accepted delivery event', function () {
    $delivery = NewsletterRequest::query()->create([
        'original_request' => ['domain' => 'example.com'],
    ])->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ])->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'recipient' => 'person@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
    ]);

    $message = (new Email())
        ->from(new Address('newsletter@example.com'))
        ->to(new Address('person@example.com'))
        ->subject('Hello')
        ->text('Body');

    $message->getHeaders()->addTextHeader('X-Newsletter-Delivery-Id', (string) $delivery->id);
    $message->getHeaders()->addTextHeader('X-Resend-Email-ID', 're_123');

    $sentMessage = new SymfonySentMessage($message, new Envelope(
        sender: new Address('newsletter@example.com'),
        recipients: [new Address('person@example.com')],
    ));

    resolve(RecordAcceptedNewsletterDelivery::class)->handle(
        new MessageSent(new \Illuminate\Mail\SentMessage($sentMessage)),
    );

    $delivery->refresh();

    expect($delivery->latest_event)->toBe('accepted')
        ->and($delivery->provider_message_id)->toBe('re_123')
        ->and($delivery->events)->toHaveCount(1)
        ->and($delivery->events->sole()->event)->toBe('accepted');
});

test('message sent listener ignores duplicate accepted events for the same provider message', function () {
    $delivery = NewsletterRequest::query()->create([
        'original_request' => ['domain' => 'example.com'],
    ])->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ])->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'recipient' => 'person@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
    ]);

    $message = (new Email())
        ->from(new Address('newsletter@example.com'))
        ->to(new Address('person@example.com'))
        ->subject('Hello')
        ->text('Body');

    $message->getHeaders()->addTextHeader('X-Newsletter-Delivery-Id', (string) $delivery->id);
    $message->getHeaders()->addTextHeader('X-Resend-Email-ID', 're_123');

    $sentMessage = new SymfonySentMessage($message, new Envelope(
        sender: new Address('newsletter@example.com'),
        recipients: [new Address('person@example.com')],
    ));

    $event = new MessageSent(new \Illuminate\Mail\SentMessage($sentMessage));

    resolve(RecordAcceptedNewsletterDelivery::class)->handle($event);
    resolve(RecordAcceptedNewsletterDelivery::class)->handle($event);

    $delivery->refresh();

    expect($delivery->events)->toHaveCount(1)
        ->and($delivery->events->sole()->provider_event_id)->toBe('message.sent:re_123');
});

test('mailgun events endpoint returns stored delivery events', function () {
    config()->set('services.mailgun.key', 'test-mailgun-key');

    $delivery = NewsletterRequest::query()->create([
        'original_request' => ['domain' => 'example.com'],
    ])->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ])->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'recipient' => 'person@example.com',
        'mailgun_message_id' => 'ghost-id-123',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
        'tags' => ['ghost-email'],
        'user_variables' => ['email_id' => 'ghost-id-123'],
    ]);

    $delivery->events()->create([
        'event' => 'opened',
        'provider_event' => 'email.opened',
        'occurred_at' => now(),
    ]);

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->getJson(route('mailgun.events', ['domain' => 'example.com']))
        ->assertSuccessful()
        ->assertJsonPath('items.0.event', 'opened')
        ->assertJsonPath('items.0.recipient', 'person@example.com')
        ->assertJsonPath('items.0.message.headers.message-id', 'ghost-id-123')
        ->assertJsonPath('items.0.tags.0', 'ghost-email')
        ->assertJsonPath('items.0.user-variables.email-id', 'ghost-id-123')
        ->assertJsonPath('paging.first', route('mailgun.events', ['domain' => 'example.com', 'page' => base64_encode('0')]))
        ->assertJsonPath('paging.next', route('mailgun.events', ['domain' => 'example.com', 'page' => base64_encode('1')]))
        ->assertJsonMissingPath('paging.previous');
});

test('mailgun events endpoint returns next empty page after the last non-empty page', function () {
    config()->set('services.mailgun.key', 'test-mailgun-key');

    $delivery = NewsletterRequest::query()->create([
        'original_request' => ['domain' => 'example.com'],
    ])->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ])->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'recipient' => 'person@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
    ]);

    $delivery->events()->create([
        'event' => 'opened',
        'occurred_at' => now(),
    ]);

    $delivery->events()->create([
        'event' => 'opened',
        'occurred_at' => now()->addSecond(),
    ]);

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->getJson(route('mailgun.events', ['domain' => 'example.com', 'limit' => 1, 'ascending' => 'yes']))
        ->assertSuccessful()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('paging.next', route('mailgun.events', ['domain' => 'example.com', 'page' => base64_encode('1'), 'limit' => 1, 'ascending' => 'yes']))
        ->assertJsonMissingPath('paging.previous');

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->getJson(route('mailgun.events', ['domain' => 'example.com', 'page' => base64_encode('1'), 'limit' => 1, 'ascending' => 'yes']))
        ->assertSuccessful()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('paging.previous', route('mailgun.events', ['domain' => 'example.com', 'page' => base64_encode('0'), 'limit' => 1, 'ascending' => 'yes']))
        ->assertJsonPath('paging.next', route('mailgun.events', ['domain' => 'example.com', 'page' => base64_encode('2'), 'limit' => 1, 'ascending' => 'yes']));

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->getJson(route('mailgun.events', ['domain' => 'example.com', 'page' => base64_encode('2'), 'limit' => 1, 'ascending' => 'yes']))
        ->assertSuccessful()
        ->assertJsonCount(0, 'items')
        ->assertJsonMissingPath('paging.next');
});

test('mailgun events endpoint supports mailgun or event filters', function () {
    config()->set('services.mailgun.key', 'test-mailgun-key');

    $delivery = NewsletterRequest::query()->create([
        'original_request' => ['domain' => 'staging-blog.cife.space'],
    ])->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ])->deliveries()->create([
        'domain' => 'staging-blog.cife.space',
        'provider' => 'resend',
        'recipient' => 'contact.brunobernard+1@gmail.com',
        'mailgun_message_id' => 'ghost-id-123',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
        'tags' => ['ghost-email'],
        'user_variables' => ['email_id' => 'ghost-id-123'],
    ]);

    $delivery->events()->create([
        'event' => 'delivered',
        'provider_event' => 'email.delivered',
        'occurred_at' => now(),
    ]);

    $delivery->events()->create([
        'event' => 'opened',
        'provider_event' => 'email.opened',
        'occurred_at' => now(),
    ]);

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->getJson(route('mailgun.events', [
        'domain' => 'staging-blog.cife.space',
        'event' => 'delivered OR failed OR unsubscribed OR complained',
        'tags' => 'bulk-email',
        'begin' => now()->subDay()->timestamp,
        'end' => now()->addDay()->timestamp,
        'ascending' => 'yes',
        'limit' => 300,
    ]))
        ->assertSuccessful()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.event', 'delivered')
        ->assertJsonPath('items.0.recipient', 'contact.brunobernard+1@gmail.com');
});
