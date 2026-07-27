<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ghost v6.53.0 — Mailgun MESSAGES surface contract fixtures
|--------------------------------------------------------------------------
|
| These fixtures pin the proxy's `POST /v3/{domain}/messages` behaviour against
| exactly what Ghost v6.53.0 sends and reads, per the W0 spike dossier
| (ghost-resend-shim-spike.md, Report A §A2a "POST /v3/{domain}/messages" and
| §A8 "surprises"). Every assertion cites the Ghost source ref that justifies it.
|
| Ghost's Mailgun HTTP client lives at core/server/services/lib/mailgun-client.js
| (mailgun.js 10.4.0); the provider that fans out per-recipient personalization is
| core/server/services/email-service/MailgunEmailProvider (mailgun-email-provider.js).
|
*/

use App\Events\NewsletterRequested;
use App\Listeners\ProcessNewsletterRequest;
use App\Mail\GhostNewsletter;
use App\Models\NewsletterRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

test('ghost messages endpoint accepts a realistic multipart batch and returns a non-empty queued id', function (): void {
    // Ghost v6.53.0 mailgun-client.js:34-131 — MailgunClient.send() POSTs multipart/form-data.
    config()->set('services.mailgun.key', 'test-mailgun-key');

    // Isolate the intake surface from the async send pipeline (the queued ProcessNewsletterRequest listener).
    Event::fake([NewsletterRequested::class]);

    $response = $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->post(route('mailgun.messages', ['domain' => 'example.com']), ghostMessagesInput());

    // Ghost v6.53.0 mailgun-email-provider.js:120-122,147 — reads ONLY `id` from the body and strips <>.
    // Any JSON carrying a non-empty `id` string satisfies the contract.
    $response->assertSuccessful();
    expect($response->json('id'))->toBeString()->not->toBeEmpty();

    // The raw Ghost request is captured verbatim for the send pipeline (RecordMailgunMessageRequest).
    /** @var array<string, mixed> $original */
    $original = NewsletterRequest::query()->sole()->original_request;
    $input = $original['input'];

    // Exact-value fields Ghost sends on every batch (mailgun-client.js:63-106).
    // `to` is repeated once per recipient (A2a). NOTE: real Mailgun/mailgun.js sends REPEATED BARE `to`
    // fields, which PHP's form parser collapses to the last value — but the proxy never relies on `to`
    // for the recipient list; it derives recipients from recipient-variables
    // (NormalizeMailgunRequest::normalizeRecipients). The Laravel test client represents repeated fields
    // as an array, which is the shape normalizeRecipients tolerates.
    expect($input)->toMatchArray([
        'from' => 'Condomera Newsletter <newsletter@condomera.com>',                    // A2a: from is "Name <addr>"
        'subject' => 'Boletin de Condomera',                                            // A2a: subject is a plain string, NO per-recipient tokens
        'h:Reply-To' => 'Soporte <soporte@condomera.com>',                             // A2a: h:Reply-To (optional)
        'h:List-Unsubscribe' => '<%recipient.list_unsubscribe%>, <%tag_unsubscribe_email%>', // A2a:78-81
        'h:List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',                     // A2a:78-81 one-click
        'v:email-id' => 'ghost-email-id-abc',                                          // A2a:84-86 — MUST be echoed in events as user-variables[email-id]
        'o:tag' => ['bulk-email', 'ghost-email'],                                       // A2a:88-92 — repeated o:tag
        'o:tracking-opens' => 'yes',                                                    // A2a:99-101
        'o:deliverytime' => 'Tue, 28 Apr 2026 12:00:00 GMT',                           // A2a:104-106 — Date.toUTCString() (RFC-1123)
        'to' => ['ada@example.com', 'bruno@example.com'],                              // A2a: repeated `to`, one per recipient
    ]);

    // Body-only personalization (A2a): html/text carry the %recipient.x% tokens; recipient-variables is a JSON string.
    expect($input['html'])->toContain('%recipient.name%')
        ->and($input['text'])->toContain('%recipient.unsubscribe_url%')
        ->and($input['recipient-variables'])->toBeString();                            // A2a: recipient-variables is a JSON string keyed by email

    Event::assertDispatched(NewsletterRequested::class);
});

test('ghost messages batch expands %recipient.x% tokens per recipient with no cross-leak', function (): void {
    // Ghost v6.53.0 mailgun-email-provider.js:59-67 (#updateRecipientVariables) + mailgun-client.js:63-106 —
    // one messages POST fans out to N individual emails, each personalized from recipient-variables
    // (Report A §A2a "Batch semantics"). The proxy resolves placeholders per recipient in
    // BuiltinProvider::send() → ResolveRecipientPlaceholders before queueing each GhostNewsletter.
    Mail::fake();

    $newsletterRequest = NewsletterRequest::query()->create([
        'original_request' => ghostOriginalRequest(ghostMessagesInput()),
    ]);

    // Drive the send pipeline exactly as the repo's own pipeline tests do (NewsletterRequestPipelineTest).
    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    // A2a batch semantics: one individual email per recipient — never one message with N visible recipients.
    Mail::assertQueued(GhostNewsletter::class, 2);

    Mail::assertQueued(GhostNewsletter::class, function (GhostNewsletter $mailable): bool {
        if ($mailable->recipient->email !== 'ada@example.com') {
            return false;
        }

        $html = (string) $mailable->request->message->html;
        $text = (string) $mailable->request->message->text;

        return str_contains($html, 'Ada')
            && str_contains($text, 'https://condomera.com/u/ada')
            && ! str_contains($html, 'Bruno')            // recipient B's values MUST NOT leak into A
            && ! str_contains($text, '/u/bruno')
            && ! str_contains($html, '%recipient.');      // every token resolved
    });

    Mail::assertQueued(GhostNewsletter::class, function (GhostNewsletter $mailable): bool {
        if ($mailable->recipient->email !== 'bruno@example.com') {
            return false;
        }

        $html = (string) $mailable->request->message->html;
        $text = (string) $mailable->request->message->text;

        return str_contains($html, 'Bruno')
            && str_contains($text, 'https://condomera.com/u/bruno')
            && ! str_contains($html, 'Ada')               // recipient A's values MUST NOT leak into B
            && ! str_contains($text, '/u/ada')
            && ! str_contains($html, '%recipient.');
    });
});

test('ghost messages strips the %tag_unsubscribe_email% macro from the List-Unsubscribe header', function (): void {
    // Ghost v6.53.0 mailgun-client.js:78-81 sends List-Unsubscribe as
    // "<%recipient.list_unsubscribe%>, <%tag_unsubscribe_email%>". %tag_unsubscribe_email% is a Mailgun
    // SERVER-SIDE macro that Ghost NEVER resolves (Report A §A8.4) — the shim must strip it
    // (ResolveRecipientPlaceholders.php:19-22 sets the macro to '' then :55-63 drops empty <> tokens),
    // otherwise a literal macro / bare "<>" would reach the MTA in the List-Unsubscribe header.
    Mail::fake();

    $newsletterRequest = NewsletterRequest::query()->create([
        'original_request' => ghostOriginalRequest(ghostMessagesInput()),
    ]);

    resolve(ProcessNewsletterRequest::class)->handle(new NewsletterRequested($newsletterRequest));

    Mail::assertQueued(GhostNewsletter::class, function (GhostNewsletter $mailable): bool {
        if ($mailable->recipient->email !== 'ada@example.com') {
            return false;
        }

        $header = (string) ($mailable->request->headers['list_unsubscribe'] ?? '');

        return $header === '<https://condomera.com/lu/ada>'      // only the recipient's URL survives
            && ! str_contains($header, '%tag_unsubscribe_email%') // macro never survives verbatim
            && ! str_contains($header, '<>');                     // no empty <> left behind
    });
});

/**
 * The canonical Ghost v6.53.0 messages multipart body (mailgun-client.js:63-106), as a field map.
 * Personalization is body-only (§A2a): the subject is a plain string; html/text carry %recipient.x% tokens.
 *
 * @return array<string, mixed>
 */
function ghostMessagesInput(array $overrides = []): array
{
    return [
        'to' => ['ada@example.com', 'bruno@example.com'],
        'from' => 'Condomera Newsletter <newsletter@condomera.com>',
        'subject' => 'Boletin de Condomera',
        'html' => '<h1>Hola %recipient.name%</h1><p>Para darte de baja visita <a href="%recipient.unsubscribe_url%">este enlace</a>.</p>',
        'text' => 'Hola %recipient.name% — para darte de baja visita %recipient.unsubscribe_url%',
        'recipient-variables' => json_encode([
            'ada@example.com' => [
                'name' => 'Ada',
                'unsubscribe_url' => 'https://condomera.com/u/ada',
                'list_unsubscribe' => 'https://condomera.com/lu/ada',
            ],
            'bruno@example.com' => [
                'name' => 'Bruno',
                'unsubscribe_url' => 'https://condomera.com/u/bruno',
                'list_unsubscribe' => 'https://condomera.com/lu/bruno',
            ],
        ], JSON_THROW_ON_ERROR),
        'h:Reply-To' => 'Soporte <soporte@condomera.com>',
        'h:List-Unsubscribe' => '<%recipient.list_unsubscribe%>, <%tag_unsubscribe_email%>',
        'h:List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        'v:email-id' => 'ghost-email-id-abc',
        'o:tag' => ['bulk-email', 'ghost-email'],
        'o:tracking-opens' => 'yes',
        'o:deliverytime' => 'Tue, 28 Apr 2026 12:00:00 GMT',
        ...$overrides,
    ];
}

/**
 * Wrap a Ghost messages body into the `original_request` envelope RecordMailgunMessageRequest stores.
 *
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function ghostOriginalRequest(array $input, string $domain = 'example.com'): array
{
    return [
        'provider' => 'mailgun',
        'route' => 'mailgun.messages',
        'url' => "http://example.test/v3/{$domain}/messages",
        'path' => "/v3/{$domain}/messages",
        'method' => 'POST',
        'domain' => $domain,
        'headers' => [],
        'query' => [],
        'input' => $input,
        'files' => [],
    ];
}
