<?php

declare(strict_types=1);

use App\Events\NewsletterRequested;
use App\Models\NewsletterRequest;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard shows newsletter request operations data', function () {
    $user = User::factory()->create();

    $request = NewsletterRequest::query()->create([
        'original_request' => [
            'domain' => 'example.com',
            'input' => [
                'subject' => 'Hello',
                'from' => 'newsletter@example.com',
                'to' => 'person@example.com',
            ],
        ],
    ]);

    $attempt = $request->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $delivery = $attempt->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'recipient' => 'person@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
        'latest_event' => 'accepted',
        'accepted_at' => now(),
    ]);

    $delivery->events()->create([
        'event' => 'accepted',
        'provider_event' => 'message.sent',
        'occurred_at' => now(),
    ]);

    $failedDelivery = $attempt->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'recipient' => 'failed@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
        'latest_event' => 'failed',
        'latest_severity' => 'temporary',
        'failed_at' => now(),
    ]);

    $failedDelivery->events()->create([
        'event' => 'failed',
        'provider_event' => 'email.failed',
        'severity' => 'temporary',
        'occurred_at' => now(),
        'payload' => ['reason' => 'Mailbox unavailable'],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('summary', 5)
            ->where('summary.2.label', 'Tracked deliveries')
            ->where('summary.2.value', '2')
            ->where('alerts.0.level', 'danger')
            ->where('recentFailures.0.recipient', 'failed@example.com')
            ->where('recentFailures.0.reason', 'Mailbox unavailable')
            ->where('delivery.metrics.0.label', 'Delivered')
            ->where('delivery.metrics.0.value', '0')
            ->has('delivery.timeline', 30)
            ->where('failureReasons.0.reason', 'Mailbox unavailable')
            ->where('failureReasons.0.count', 1)
            ->where('suppressions.metrics.0.label', 'Bounces')
            ->where('suppressions.metrics.0.value', '1')
            ->where('suppressions.rows.0.email', 'failed@example.com')
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $request->id)
            ->where('requests.data.0.status', 'failed')
            ->where('requests.data.0.attempts.0.deliveries.0.recipient', 'failed@example.com')
            ->where('requests.data.0.attempts.0.deliveries.0.events.0.event', 'failed'));
});

test('health shows delivery and integration signals', function () {
    $user = User::factory()->create();

    $request = NewsletterRequest::query()->create([
        'original_request' => [
            'domain' => 'example.com',
            'input' => [
                'subject' => 'Hello',
            ],
        ],
    ]);

    $attempt = $request->attempts()->create([
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $attempt->deliveries()->create([
        'domain' => 'example.com',
        'provider' => 'resend',
        'recipient' => 'person@example.com',
        'from' => 'newsletter@example.com',
        'subject' => 'Hello',
        'latest_event' => 'delivered',
        'delivered_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('health'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('health')
            ->where('status.state', 'ok')
            ->has('signals', 10)
            ->where('signals.0.signal', 'Outbox provider')
            ->has('checks', 5)
            ->where('checks.3.check', 'Delivery failures'));
});

test('dashboard retry action redispatches the newsletter request', function () {
    Event::fake();

    $user = User::factory()->create();
    $request = NewsletterRequest::query()->create([
        'original_request' => [
            'domain' => 'example.com',
            'input' => [
                'subject' => 'Retry me',
            ],
        ],
    ]);

    $this->actingAs($user)
        ->post(route('newsletter-requests.retry', $request))
        ->assertRedirect();

    Event::assertDispatched(NewsletterRequested::class, fn (NewsletterRequested $event) => $event->newsletterRequest->is($request));
});
