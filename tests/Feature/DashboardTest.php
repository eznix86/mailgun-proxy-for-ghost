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

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $request->id)
            ->where('requests.data.0.status', 'processed')
            ->where('requests.data.0.attempts.0.deliveries.0.recipient', 'person@example.com')
            ->where('requests.data.0.attempts.0.deliveries.0.events.0.event', 'accepted'));
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
