# How it works

[← Back to the README](../README.md)

The proxy sits in front of your real email provider and speaks Mailgun's three-endpoint dialect back to Ghost. The result: you keep Ghost's native newsletter pipeline — segmentation, member state, opens and bounces on the member timeline — while the actual delivery runs on Resend (or any Laravel mailer) instead of Mailgun.

There are two independent flows: **sending** (Ghost → provider) and **analytics** (provider → Ghost).

## What it does

- Accepts Ghost's Mailgun `POST /v3/{domain}/messages` requests (multipart, HTTP Basic auth).
- Stores the raw request, then queues the send, expanding `%recipient.*%` variables faithfully per recipient.
- Sends through Laravel's configured mailer.
- Records per-recipient deliveries and, for Resend, ingests webhook events at `POST /api/webhook/resend` (Svix-verified).
- Exposes Ghost-compatible Mailgun events at `GET /v3/{domain}/events`, with the paging contract Ghost's 5-minute poller expects.
- Acknowledges Ghost's Mailgun suppression-removal calls (`DELETE /v3/{domain}/{bounces|complaints|unsubscribes}/{address}`) as logged no-ops (see [Troubleshooting](troubleshooting.md)).

## Send flow

```
 Ghost                      Mailgun Proxy                    Provider
   |                             |                              |
   |  POST /v3/{domain}/messages |                              |
   |  multipart, Basic api:KEY   |                              |
   |---------------------------->|                              |
   |                             | EnsureMailgunBasicAuth        |
   |                             | store NewsletterRequest (raw) |
   |     200 {"id":"..."}        | fire NewsletterRequested      |
   |<----------------------------|                              |
   |                             |                              |
   |            queue worker      v                             |
   |                     normalize request                     |
   |                     expand %recipient.x% per recipient    |
   |                     send via MAIL_MAILER                  |
   |                             |----------------------------->|
   |                             |  record "accepted"            |
   |                             |  + provider message id        |
```

**Two send paths.** Which one runs depends on the provider:

- **Resend (default):** recipients are sent through Resend's batch endpoint (`POST /emails/batch`), chunked at **≤ 100 recipients per call** — a 10k-recipient newsletter collapses from 10k queued sends into ~100 batch calls. Each call carries a deterministic `Idempotency-Key` per chunk (anchored on the newsletter request id, so job retries never re-send an already-sent chunk), and the proxy records one `accepted` delivery per recipient, matching Resend's returned ids back to recipients by request order. See [`app/Outbox/ResendBatchProvider.php`](../app/Outbox/ResendBatchProvider.php).
- **Every other provider (SES, Postmark, SMTP, Sendmail, Mailbox):** one queued Laravel mailable **per recipient** through `MAIL_MAILER`, recording `accepted` from Laravel's `MessageSent` event.

Set `OUTBOX_RESEND_BATCH=false` to force Resend onto the per-recipient path too. Batch size and the pause between calls are tunable — see [Configuration](configuration.md).

## Analytics flow

```
 Provider (Resend)          Mailgun Proxy                    Ghost
   |                             |                              |
   |  POST /api/webhook/resend   |                              |
   |  Svix-signed                |                              |
   |---------------------------->|                              |
   |                             | VerifyResendWebhookSignature  |
   |                             | match email_id -> delivery    |
   |                             | store DeliveryEvent           |
   |                             | (delivered / opened / ...)    |
   |                             |                              |
   |                             |   GET /v3/{domain}/events     |
   |                             |   every ~5 min, Basic auth    |
   |                             |<-----------------------------|
   |                             |  Mailgun-shaped page:         |
   |                             |  { items: [...],              |
   |                             |    paging: { next } }         |
   |                             |----------------------------->|
   |                             |     update opens / bounces /  |
   |                             |     member state              |
```

## Why two directions?

Ghost never receives provider webhooks itself — it only polls Mailgun for events. So the proxy has to both *receive* real-time webhooks from the provider and *replay* them, on demand, in Mailgun's shape whenever Ghost's poller asks. Events are matched to deliveries by the `email-id` variable that Ghost stamps on every message and the proxy echoes back.

`email.sent` webhooks from Resend are ignored, because the initial `accepted` state is already recorded at send time. Events are de-duplicated by the provider's event id, so redelivered webhooks are safe.

> **Analytics parity is Resend-only today.** Any supported provider can *send*, but the webhook → events loop that feeds Ghost's opens/bounces/complaints is currently implemented for Resend only. With another provider, Ghost still records each recipient as *sent* and marks hard send failures, but opens, clicks, and remote bounces will not flow back until a webhook ingestor exists for that provider.

## See also

- [Configuration](configuration.md) — environment variables and provider setup.
- [Ghost setup](ghost-setup.md) — the Ghost-side config and its gotchas.
- [Troubleshooting](troubleshooting.md) — when events don't line up.
