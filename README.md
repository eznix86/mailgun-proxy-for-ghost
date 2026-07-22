# Mailgun Proxy for Ghost

This is a Mailgun-compatible proxy built specifically for Ghost newsletters. Ghost sends newsletter mail through the proxy as if it were Mailgun; the proxy sends through Laravel and exposes Mailgun-shaped events back to Ghost analytics.

The result: you keep Ghost's native newsletter pipeline — segmentation, member state, opens and bounces on the member timeline — while the actual delivery runs on Resend (or any Laravel mailer) instead of Mailgun.

## Supported Providers

- Resend
- SMTP
- Sendmail
- Postmark
- Amazon SES
- [Mailbox](https://github.com/RedberryProducts/mailbox-for-laravel) (fakes a mailbox without sending for real, great for local testing)
- And more if you install other Laravel mailer packages by the community

> **Analytics parity is Resend-only today.** Any provider above can *send*, but the webhook → events loop that feeds Ghost's opens/bounces/complaints is currently implemented for Resend only (`POST /api/webhook/resend`). With another provider, Ghost still records each recipient as *sent* (via Laravel's `MessageSent` event) and marks hard send failures, but opens, clicks, and remote bounces will not flow back until a webhook ingestor exists for that provider.

## What it does

- Accepts Ghost's Mailgun `POST /v3/{domain}/messages` requests (multipart, HTTP Basic auth).
- Stores the raw request, then queues one Laravel mailable **per recipient**, expanding `%recipient.*%` variables faithfully.
- Sends each recipient through Laravel's configured mailer.
- Records per-recipient deliveries and, for Resend, ingests webhook events at `POST /api/webhook/resend` (Svix-verified).
- Exposes Ghost-compatible Mailgun events at `GET /v3/{domain}/events`, with the paging contract Ghost's 5‑minute poller expects.

## How it works

The proxy sits in front of your real email provider and speaks Mailgun's three-endpoint dialect back to Ghost. There are two independent flows: **sending** (Ghost → provider) and **analytics** (provider → Ghost).

### Send flow

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
   |                     one queued Mailable per recipient     |
   |                             |   send via MAIL_MAILER        |
   |                             |----------------------------->|
   |                             |  MessageSent -> record        |
   |                             |  "accepted" + provider msg id |
```

### Analytics flow

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

**Why two directions?** Ghost never receives provider webhooks itself — it only polls Mailgun for events. So the proxy has to both *receive* real-time webhooks from the provider and *replay* them, on demand, in Mailgun's shape whenever Ghost's poller asks. Events are matched to deliveries by the `email-id` variable that Ghost stamps on every message and the proxy echoes back.

## Requirements

- PHP 8.3+ and a queue worker (delivery and analytics both run on the queue).
- A database (SQLite by default; MySQL or PostgreSQL also supported).
- A public origin (see the Ghost base-URL gotcha below) reachable by both Ghost and your provider's webhooks.
- Provider credentials (a Resend API key + webhook secret for the full analytics loop).

## Configuration

### Environment variables

Add these to the proxy `.env`. Only a handful are proxy-specific; the rest are standard Laravel settings.

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `APP_KEY` | Yes | — | Laravel app key. Run `php artisan key:generate` once. |
| `APP_URL` | Yes | `http://localhost` | The proxy's public URL. Used to build the `paging` URLs returned to Ghost. |
| `MAILGUN_API_KEY` | Yes | — | Shared secret Ghost sends as its Mailgun API key. Checked as the HTTP Basic password (username is always `api`). |
| `MAIL_MAILER` | Yes | `log` | The Laravel mailer that actually sends: `resend`, `ses`, `postmark`, `smtp`, `sendmail`, `mailbox`, `log`. |
| `OUTBOX_PROVIDER` | No | falls back to `MAIL_MAILER`, then `mailbox` | Labels deliveries and enables the Resend rate limiter when set to `resend`. Normally set equal to `MAIL_MAILER`. |
| `RESEND_API_KEY` | For Resend | — | Resend API key, used by the mailer and the webhook-verification client. |
| `RESEND_WEBHOOK_SECRET` | For Resend analytics | — | Svix/Resend webhook signing secret used to verify incoming events. |
| `POSTMARK_API_KEY` | For Postmark | — | Postmark server token. |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` | For SES | region `us-east-1` | Amazon SES credentials. |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_SCHEME` | For SMTP | — | SMTP transport settings. |
| `QUEUE_CONNECTION` | No | `database` | Queue backend. A worker **must** be running (see Deployment). |
| `DB_CONNECTION` | No | `sqlite` | `sqlite`, `mysql`, or `pgsql`. Set `DB_*` accordingly for a server database. |

A minimal Resend configuration:

```dotenv
APP_URL=https://newsletter-proxy.domain.tld

MAIL_MAILER=resend
OUTBOX_PROVIDER=resend

MAILGUN_API_KEY=change-this-shared-api-key
RESEND_API_KEY=re_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
RESEND_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Notes:

- `MAILGUN_API_KEY` is the shared key Ghost uses as its Mailgun API key. `MAIL_MAILER` selects how mail is actually delivered; `OUTBOX_PROVIDER` should match it.
- `RESEND_API_KEY` is used by Laravel's Resend mailer.
- `RESEND_WEBHOOK_SECRET` is the Svix/Resend webhook signing secret.

### Ghost configuration

In Ghost's config file (`config.production.json`), point the bulk-email Mailgun settings at this proxy:

```json
{
  "bulkEmail": {
    "mailgun": {
      "baseUrl": "https://newsletter-proxy.domain.tld/",
      "apiKey": "change-this-shared-api-key",
      "domain": "domain.tld"
    }
  }
}
```

Notes:

- `apiKey` must match `MAILGUN_API_KEY` in the proxy `.env`.
- `domain` should be the Ghost newsletter sending domain. The proxy scopes events per `domain`.

Two Ghost behaviors are worth knowing before you deploy — both come from how Ghost reads this config:

- **Ghost keeps only the *origin* of `baseUrl`.** Ghost runs `new URL(baseUrl).origin` and throws the path away, so the proxy must live at the **root of its own origin** (e.g. `https://newsletter-proxy.domain.tld/`) and serve the literal `/v3/...` paths there. You **cannot** mount it under a sub-path such as `https://domain.tld/mailgun/`. Give it its own subdomain (or its own host) behind your reverse proxy.
- **A partial `bulkEmail.mailgun` block silently shadows the admin UI.** If *any* truthy `bulkEmail.mailgun` object exists in Ghost's config file, it fully overrides the Mailgun settings entered in **Settings → Email newsletters** — even if the config block is incomplete. Configure Mailgun in *one* place (the config file is recommended here) to avoid a half-applied config that silently sends nowhere.

Transactional email (member sign-in links, password resets, etc.) is a **separate** seam in Ghost and does not go through this proxy. If you are moving off Mailgun entirely, point Ghost's `mail` (SMTP) transport straight at your provider's SMTP endpoint — for Resend, that's [Resend SMTP](https://resend.com/docs/send-with-smtp). This proxy is bulk-newsletter-only.

### Provider setup

#### Resend (full analytics)

1. Create a Resend API key and set `RESEND_API_KEY`.
2. Add a webhook in the Resend dashboard pointing to:

   ```text
   https://newsletter-proxy.domain.tld/api/webhook/resend
   ```

   Subscribe to at least the `email.delivered`, `email.opened`, `email.bounced`, `email.complained`, `email.failed`, and `email.delivery_delayed` events.
3. Copy the webhook's signing secret into `RESEND_WEBHOOK_SECRET`. The proxy rejects any webhook whose Svix signature does not verify.
4. On your Resend domain, **enable open tracking**. **Do not enable click tracking** — Ghost rewrites and tracks links itself before handing the HTML to the proxy, so provider-side click tracking would double-rewrite the links and break Ghost's click analytics.

The Resend outbox is rate-limited to 5 sends/second inside the proxy to stay under Resend's default 10 req/s ceiling.

#### SES, Postmark, SMTP, Sendmail

Set `MAIL_MAILER` (and `OUTBOX_PROVIDER`) to the provider and supply its credentials from the table above. These providers **send** correctly, but there is currently no webhook ingestor for them, so open/bounce/complaint analytics will not be reported back to Ghost — only the initial *accepted* state and local send failures are tracked. Use Resend if analytics parity matters.

#### Mailbox (local testing)

Set `MAIL_MAILER=mailbox` to capture newsletters locally without sending anything, using [Mailbox for Laravel](https://github.com/RedberryProducts/mailbox-for-laravel). This is the default outside production.

## Deployment

### Docker Compose

```bash
cp .env.example .env
# Edit .env with your configuration (at minimum APP_KEY via key:generate,
# APP_URL, MAILGUN_API_KEY, MAIL_MAILER, and your provider credentials)

docker compose up -d --build
docker compose exec app php artisan key:generate
```

The app is available at `http://localhost:8080`.

Good to know about the image:

- The container runs nginx, PHP-FPM, **and the queue worker** together (via a Procfile), so you do not need to start a separate worker for Docker deployments.
- Database **migrations run automatically** on container start (`RUN_MIGRATIONS=true` by default). You only need `key:generate` if `APP_KEY` is empty.
- The image defaults to a **SQLite** database persisted in the `data` volume (`/data/database.sqlite`). The bundled `docker-compose.yml` also starts a PostgreSQL service; to actually use it, set `DB_CONNECTION=pgsql` and the `DB_*` variables in `.env`.
- An unauthenticated health endpoint is exposed at `/up` for load balancers and orchestrators.

**Reverse proxy:** terminate TLS in front of the container and forward the proxy's own origin root to port `8080`. Because Ghost discards the path of `baseUrl`, the proxy must own the whole origin (a dedicated subdomain is the simplest setup). Forward `X-Forwarded-Proto`/`X-Forwarded-For`; the bundled nginx already trusts private ranges for real-IP resolution.

### Local setup

```bash
composer install
bun install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Run the app and a queue worker:

```bash
composer run dev
php artisan queue:work
```

### Queue worker

A running queue worker is **mandatory**, not optional — both the outbound send (one queued mailable per recipient) and Resend webhook processing depend on it. If the worker is not running, requests will pile up as *queued* on the dashboard and nothing is delivered. In Docker this is handled for you; on a bare-metal or Forge-style deploy, run `php artisan queue:work` under a supervisor.

## Ghost analytics events

Ghost polls this proxy for Mailgun events every ~5 minutes and reconciles them against member state. The proxy stores and serves these event types:

- `delivered`
- `opened`
- `clicked`
- `failed`
- `rejected`
- `complained`

`email.sent` webhooks from Resend are ignored, because Laravel's `MessageSent` event already records the initial `accepted` state at send time. Events are de-duplicated by the provider's event id, so redelivered webhooks are safe.

## Dashboard

The proxy includes a Laravel + Inertia dashboard for inspecting newsletter requests, attempts, per-recipient deliveries, and webhook events, and for retrying failed requests.

```text
/dashboard
```

- `/dashboard` — delivery summary, failure/complaint rates, a 30‑day timeline, suppression signals, and a scrollable request log with retry.
- `/health` — a live status board of the proxy's configuration and delivery signals.
- The dashboard is behind login. Registration is only available while **no user exists yet**, so the first visit after deploy lets you create the single admin account and then locks registration.

## Troubleshooting

**Ghost shows "Set up Mailgun to start sending newsletters!"** — Ghost thinks Mailgun is not configured. Check that `bulkEmail.mailgun` in Ghost's config file has all three of `baseUrl`, `apiKey`, and `domain`, and remember that a partial block there overrides the admin UI.

**`401 Unauthorized` from the proxy on send** — the API key mismatched. Ghost authenticates as HTTP Basic `api` : `<apiKey>`; the proxy compares `<apiKey>` against `MAILGUN_API_KEY`. Make sure the two match exactly. A `503` with "Mailgun proxy not configured" means `MAILGUN_API_KEY` is empty on the proxy.

**Requests stuck as "Queued" on the dashboard** — no queue worker is running. Start `php artisan queue:work` (or check the worker process in Docker).

**Newsletters send but opens/bounces never appear in Ghost** — you are either not on Resend, or the webhook is not reaching the proxy. Confirm the Resend webhook points at `/api/webhook/resend`, that `RESEND_WEBHOOK_SECRET` matches the webhook's signing secret (a mismatch yields `401`/`503` on the webhook and no stored events), and that open tracking is enabled on the Resend domain.

**Events reach the proxy but Ghost still shows nothing** — Ghost matches events to messages by the `email-id` variable. Confirm the proxy is reachable at its **origin root** serving `/v3/...` (not under a sub-path), since Ghost discards any path in `baseUrl`.

**Webhook `401 Invalid webhook signature`** — the Svix signature failed. Verify `RESEND_WEBHOOK_SECRET` and that your reverse proxy is not altering the raw request body (signature verification runs over the exact bytes received).

## License

Copyright (C) 2026 Bruno Bernard

This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the [GNU Affero General Public License](LICENSE) for more details.

Because the AGPL-3.0 covers network use, anyone who runs a modified version of this proxy as a network service must make the corresponding source available to its users.
