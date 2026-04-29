# Mailgun Proxy for Ghost

This is Mailgun-compatible proxy built specifically for Ghost newsletters. Ghost sends newsletter mail through the proxy as if it were Mailgun; the proxy sends through Laravel and exposes Mailgun-shaped events back to Ghost analytics.

## What it does

- Accepts Ghost's Mailgun `POST /v3/{domain}/messages` requests.
- Sends each recipient through Laravel's configured mailer.
- Records per-recipient deliveries and webhook events.
- Accepts Resend webhooks at `POST /api/webhook/resend`.
- Exposes Ghost-compatible Mailgun events at `GET /v3/{domain}/events`.

## Laravel environment

Add these values to the proxy `.env`:

```dotenv
APP_URL=https://newsletter-proxy.domain.tld

MAIL_MAILER=resend
OUTBOX_PROVIDER=resend

MAILGUN_API_KEY=change-this-shared-api-key
RESEND_API_KEY=re_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
RESEND_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Notes:

- `MAILGUN_API_KEY` is the shared key Ghost uses as its Mailgun API key.
- `RESEND_API_KEY` is used by Laravel's Resend mailer.
- `RESEND_WEBHOOK_SECRET` is the Svix/Resend webhook signing secret.
- Set the Resend webhook URL to:

```text
https://newsletter-proxy.domain.tld/api/webhook/resend
```

Enable Resend domain tracking for opens and clicks:

```text
https://resend.com/docs/dashboard/domains/tracking
```

## Ghost configuration

In Ghost's config file, point bulk email Mailgun settings to this proxy:

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

- `baseUrl` must be the proxy URL and should include the trailing slash.
- `apiKey` must match `MAILGUN_API_KEY` in the proxy `.env`.
- `domain` should be the Ghost newsletter sending domain.

## Local setup

```bash
composer install
bun install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Run the app and queue worker:

```bash
composer run dev
php artisan queue:work
```

## Ghost analytics flow

Ghost polls Mailgun events from this proxy. The proxy returns stored delivery events in the shape Ghost expects, including:

- `delivered`
- `opened`
- `clicked`
- `failed`
- `rejected`
- `complained`

`email.sent` webhooks from Resend are ignored because Laravel's `MessageSent` event records the initial `accepted` state.

## Dashboard

The proxy includes a Laravel/Inertia dashboard for inspecting newsletter requests, attempts, deliveries, webhook events, and retrying failed requests.

```text
/dashboard
```
