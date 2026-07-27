<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ghost v6.53.0 — Mailgun EVENTS surface contract fixtures
|--------------------------------------------------------------------------
|
| These fixtures pin the proxy's `GET /v3/{domain}/events` + `GET /v3/{domain}/events/{page}`
| behaviour against Ghost v6.53.0's polling + pagination contract, per the W0 spike dossier
| (ghost-resend-shim-spike.md, Report A §A2b "GET /v3/{domain}/events" and §A3 "per-event contract").
|
| Ghost polls via core/server/services/email-analytics/lib/EmailAnalyticsProviderMailgun
| (email-analytics-provider-mailgun.js) and core/server/services/lib/mailgun-client.js, over
| mailgun.js 10.4.0 whose `parsePageLinks` derives the page token from the LAST '/'-segment of
| each paging URL. Several assertions here document CONTRACT DEVIATIONS (marked "DEVIATION") that
| are pinned as current behaviour and called out in the accompanying PR deviation report.
|
*/

use App\Models\NewsletterRequest;
use App\Models\NewsletterRequestAttempt;
use App\Models\NewsletterRequestDelivery;
use Carbon\CarbonImmutable;

test('events expose the exact fields Ghost normalizeEvent reads', function (): void {
    // Ghost v6.53.0 mailgun-client.js:304-328 (normalizeEvent) + email-event-processor.js:265-311.
    config()->set('services.mailgun.key', 'test-mailgun-key');

    $occurredAt = CarbonImmutable::parse('2026-04-28 12:00:00');
    $delivery = ghostEventsDelivery(['recipient' => 'ada@condomera.com']);
    $delivery->events()->create([
        'event' => 'delivered',
        'provider_event' => 'email.delivered',
        'occurred_at' => $occurredAt,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->getJson(route('mailgun.events', ['domain' => 'example.com']));

    $response->assertSuccessful()
        ->assertJsonPath('items.0.event', 'delivered')                                     // A3: event type
        ->assertJsonPath('items.0.recipient', 'ada@condomera.com')                         // A3: recipient
        ->assertJsonPath('items.0.timestamp', $occurredAt->getTimestamp())                 // A3: timestamp = epoch SECONDS (Ghost ×1000 at mailgun-client.js:320)
        ->assertJsonPath('items.0.message.headers.message-id', 'ghost-email-id-abc')       // A3: BARE id echoing v:email-id
        ->assertJsonPath('items.0.user-variables.email-id', 'ghost-email-id-abc');         // A3: echo of v:email-id — Ghost PREFERS this over the message-id lookup

    // A3: event id present and a non-empty string (Ghost uses it as the dedupe / failure event_id key).
    expect($response->json('items.0.id'))->toBeString()->not->toBeEmpty();

    // timestamp must be a NUMBER (epoch seconds), not an ISO string, so Ghost's `timestamp * 1000` is valid.
    expect($response->json('items.0.timestamp'))->toBeInt();

    // DEVIATION (D1 — inert): Ghost matches an event to its batch via message.headers[message-id] ==
    // email_batches.provider_id (the id the messages endpoint returned). The proxy returns a CONSTANT
    // 'message-id' from POST /messages, yet emits the Ghost email id here — so message-id does NOT
    // round-trip to the messages response. This is harmless: user-variables[email-id] is present and
    // Ghost prefers it, skipping the provider-id lookup entirely (A3). Pinned so the shape stays stable.
    expect($response->json('items.0.message.headers.message-id'))
        ->toBe($response->json('items.0.user-variables.email-id'));
});

test('events pagination follows paging.next tokens, keeps next on every non-empty page, and terminates on an empty page', function (): void {
    // Ghost v6.53.0 mailgun-client.js:243-258 — Ghost follows page.pages.next.page; mailgun.js 10.4.0
    // parsePageLinks takes the LAST '/'-segment of the paging URL as the token, then re-requests
    // GET /v3/{domain}/events/{token} with the ORIGINAL query params re-appended. The loop stops ONLY on
    // an empty `items` array; a MISSING paging.next on a page that HAS items throws a TypeError (§A2b).
    config()->set('services.mailgun.key', 'test-mailgun-key');

    $delivery = ghostEventsDelivery();
    foreach ([0, 1, 2] as $i) {
        $delivery->events()->create([
            'event' => 'delivered',
            'occurred_at' => CarbonImmutable::parse('2026-04-28 12:00:00')->addSeconds($i),
        ]);
    }

    $auth = ['Authorization' => 'Basic '.base64_encode('api:test-mailgun-key')];
    $query = ['limit' => 1, 'ascending' => 'yes'];   // limit=1 forces multi-page walk (Ghost sends 300)

    $response = $this->withHeaders($auth)->getJson(route('mailgun.events', ['domain' => 'example.com', ...$query]));

    $collected = [];
    $pages = 0;

    while (true) {
        $response->assertSuccessful()->assertJsonStructure(['items', 'paging']);   // A2b: response is {items, paging}
        $items = $response->json('items');
        $pages++;

        if ($items === []) {
            // Terminal page: no paging.next — this is the ONLY signal Ghost uses to stop.
            $response->assertJsonMissingPath('paging.next');

            break;
        }

        // A2b: every page WITH items MUST carry paging.next (else Ghost throws a TypeError).
        $next = $response->json('paging.next');
        expect($next)->toBeString();

        foreach ($items as $item) {
            $collected[] = $item['timestamp'];
        }

        // Emulate mailgun.js parsePageLinks: token = LAST path segment; re-request with the original query.
        $token = basename((string) parse_url($next, PHP_URL_PATH));
        $response = $this->withHeaders($auth)->getJson("/v3/example.com/events/{$token}?".http_build_query($query));

        expect($pages)->toBeLessThan(10);   // safety net against a runaway paging loop
    }

    // Three item-bearing pages, then one empty terminal page.
    expect($pages)->toBe(4)
        ->and($collected)->toHaveCount(3);

    // A2b + ascending=yes: stable ascending timestamp order (Ghost's cursor logic assumes it).
    $sorted = $collected;
    sort($sorted);
    expect($collected)->toBe($sorted);
});

test('events honor the event filter and never surface hard-bounce (rejected) rows to Ghost', function (): void {
    // Ghost v6.53.0 email-analytics-service.js:197,216 runs an opened-only pipeline and a
    // "delivered OR failed OR unsubscribed OR complained" pipeline (§A2b). ListMailgunEvents splits the
    // OR-list on " OR " and applies whereIn('event', ...).
    config()->set('services.mailgun.key', 'test-mailgun-key');

    $delivery = ghostEventsDelivery();
    $at = CarbonImmutable::parse('2026-04-28 12:00:00');
    foreach (['delivered', 'opened', 'complained', 'rejected'] as $i => $event) {
        $delivery->events()->create(['event' => $event, 'occurred_at' => $at->addSeconds($i)]);
    }

    $auth = ['Authorization' => 'Basic '.base64_encode('api:test-mailgun-key')];

    // Opened-only pipeline: event=opened returns just the opened row.
    $this->withHeaders($auth)->getJson(route('mailgun.events', ['domain' => 'example.com', 'event' => 'opened', 'ascending' => 'yes']))
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.event', 'opened');

    // The full five-type filter Ghost sends returns delivered + opened + complained (in ascending order).
    $response = $this->withHeaders($auth)->getJson(route('mailgun.events', [
        'domain' => 'example.com',
        'event' => 'delivered OR opened OR failed OR unsubscribed OR complained',
        'ascending' => 'yes',
    ]));
    $response->assertJsonCount(3, 'items');
    expect(collect($response->json('items'))->pluck('event')->all())->toBe(['delivered', 'opened', 'complained']);

    // DEVIATION (D2 — significant): a Resend hard bounce is stored as event='rejected'
    // (ResendWebhookController::EVENTS['email.bounced'] = ['rejected', null]). Mailgun models a hard bounce
    // as `failed` + severity=permanent (A3). Because 'rejected' is NOT one of the five types Ghost ever
    // filters on, the bounce is silently EXCLUDED from every Ghost poll — hard-bounced recipients are never
    // marked failed or suppressed in Ghost. Pinned as current behaviour; see the deviation report. (A fix
    // means remapping the webhook to failed/permanent, which changes an established internal state and
    // breaks ResendWebhookTest — out of scope for a fixtures PR.)
    expect(collect($response->json('items'))->pluck('event')->all())->not->toContain('rejected');
});

test('events honor limit and the begin/end epoch-second window', function (): void {
    // Ghost v6.53.0 email-analytics-provider-mailgun.js:4,31-32 — limit=300 (PAGE_LIMIT) and begin/end as
    // epoch SECONDS (getTime()/1000, a FLOAT). ListMailgunEvents caps limit at 300 and filters occurred_at.
    // NOTE: the proxy truncates the begin/end float to an int (Request::integer) — benign at the
    // second-granularity the event store persists (documented as minor deviation D4).
    config()->set('services.mailgun.key', 'test-mailgun-key');

    $delivery = ghostEventsDelivery();
    $center = CarbonImmutable::parse('2026-04-28 12:00:00');
    $delivery->events()->create(['event' => 'delivered', 'occurred_at' => $center->subHours(2)]);   // before window
    $delivery->events()->create(['event' => 'delivered', 'occurred_at' => $center]);                 // inside window
    $delivery->events()->create(['event' => 'delivered', 'occurred_at' => $center->addHours(2)]);    // after window

    $auth = ['Authorization' => 'Basic '.base64_encode('api:test-mailgun-key')];

    // Ghost sends limit=300; all three fit under the cap.
    $this->withHeaders($auth)->getJson(route('mailgun.events', ['domain' => 'example.com', 'limit' => 300, 'ascending' => 'yes']))
        ->assertJsonCount(3, 'items');

    // begin/end (epoch seconds) narrow the window to the single in-window event.
    $this->withHeaders($auth)->getJson(route('mailgun.events', [
        'domain' => 'example.com',
        'begin' => $center->subHour()->getTimestamp(),
        'end' => $center->addHour()->getTimestamp(),
        'ascending' => 'yes',
    ]))->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.timestamp', $center->getTimestamp());

    // limit caps page size (the store never returns more rows than requested).
    $this->withHeaders($auth)->getJson(route('mailgun.events', ['domain' => 'example.com', 'limit' => 1, 'ascending' => 'yes']))
        ->assertJsonCount(1, 'items');
});

test('failed events expose severity but omit delivery-status (documented gap)', function (): void {
    // Ghost v6.53.0 mailgun-client.js:322-326 + newsletter-email-analytics-batch-processor.js:152-177 —
    // for `failed` events Ghost reads `severity` (permanent vs anything-else → temporary) AND
    // delivery-status.{message, code, enhanced-code} (§A3).
    config()->set('services.mailgun.key', 'test-mailgun-key');

    // A Resend soft failure: email.delivery_delayed → ['failed', 'temporary'] (ResendWebhookController::EVENTS).
    $delivery = ghostEventsDelivery();
    $delivery->events()->create([
        'event' => 'failed',
        'provider_event' => 'email.delivery_delayed',
        'severity' => 'temporary',
        'occurred_at' => CarbonImmutable::parse('2026-04-28 12:00:00'),
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->getJson(route('mailgun.events', ['domain' => 'example.com', 'event' => 'failed', 'ascending' => 'yes']));

    $response->assertSuccessful()
        ->assertJsonPath('items.0.event', 'failed')
        ->assertJsonPath('items.0.severity', 'temporary');   // A3: severity present → Ghost records a temporary failure

    // DEVIATION (D3): MailgunEventResource emits no `delivery-status` object, so Ghost's EmailRecipientFailure
    // rows never carry the bounce message/code, and the Mailgun 605/607 codes that drive local suppression
    // inserts never appear (§A3 flags this as an expected delta). Furthermore the webhook never produces
    // severity='permanent', so no failure is ever classified permanent. Pinned as current behaviour.
    $response->assertJsonMissingPath('items.0.delivery-status');
});

test('events accept the tags filter but do not apply it (documented gap)', function (): void {
    // Ghost v6.53.0 index.ts:56-59 sends tags=bulk-email for newsletters (§A2b) and expects the provider to
    // scope events by tag. ListMailgunEvents::query() reads no `tags` param — it is accepted but ignored.
    config()->set('services.mailgun.key', 'test-mailgun-key');

    // Delivery tagged ONLY 'ghost-email' (NOT 'bulk-email').
    $delivery = ghostEventsDelivery(['tags' => ['ghost-email']]);
    $delivery->events()->create(['event' => 'delivered', 'occurred_at' => CarbonImmutable::parse('2026-04-28 12:00:00')]);

    // DEVIATION (D5): filtering by tags=bulk-email STILL returns the ghost-email-only event, proving tags is
    // ignored. Benign for a newsletter-only proxy (all events share the bulk-email/ghost-email tag class;
    // automation-email polling is not implemented), but a real deviation once automation analytics land.
    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->getJson(route('mailgun.events', ['domain' => 'example.com', 'tags' => 'bulk-email', 'ascending' => 'yes']))
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.event', 'delivered');
});

test('events are scoped to the requested domain', function (): void {
    // Ghost polls GET /v3/{domain}/events per sending domain; the proxy scopes events by the delivery domain
    // (ListMailgunEvents::query whereHas delivery.domain) so a shared shim never leaks one tenant's events.
    config()->set('services.mailgun.key', 'test-mailgun-key');

    ghostEventsDelivery(['domain' => 'other-tenant.example.com'])
        ->events()->create(['event' => 'delivered', 'occurred_at' => CarbonImmutable::parse('2026-04-28 12:00:00')]);

    $this->withHeaders(['Authorization' => 'Basic '.base64_encode('api:test-mailgun-key')])
        ->getJson(route('mailgun.events', ['domain' => 'example.com']))
        ->assertJsonCount(0, 'items');
});

/**
 * Seed a delivery (via NewsletterRequest → attempt → delivery) carrying the fields the events endpoint reads.
 * Mirrors the chained-create seeding pattern used across the repo's feature tests.
 *
 * @param  array<string, mixed>  $overrides
 */
function ghostEventsDelivery(array $overrides = []): NewsletterRequestDelivery
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
        'recipient' => 'person@example.com',
        'mailgun_message_id' => 'ghost-email-id-abc',   // BARE Ghost email id (echo of v:email-id)
        'from' => 'newsletter@condomera.com',
        'subject' => 'Boletin',
        'tags' => ['bulk-email', 'ghost-email'],
        'user_variables' => ['email_id' => 'ghost-email-id-abc'],
        ...$overrides,
    ]);

    return $delivery;
}
