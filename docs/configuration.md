# Configuration

[← Back to the README](../README.md)

## Requirements

- PHP 8.3+ and a queue worker (delivery and analytics both run on the queue).
- A database (SQLite by default; MySQL or PostgreSQL also supported).
- A public origin (see the [Ghost base-URL gotcha](ghost-setup.md)) reachable by both Ghost and your provider's webhooks.
- Provider credentials (a Resend API key + webhook secret for the full analytics loop).

## Environment variables

Add these to the proxy `.env`. Only a handful are proxy-specific; the rest are standard Laravel settings.

| Variable | Required | Default | Purpose |
|---|---|---|---|
| `APP_KEY` | Yes | — | Laravel app key. Run `php artisan key:generate` once. |
| `APP_URL` | Yes | `http://localhost` | The proxy's public URL. Used to build the `paging` URLs returned to Ghost. |
| `MAILGUN_API_KEY` | Yes | — | Shared secret Ghost sends as its Mailgun API key. Checked as the HTTP Basic password (username is always `api`). |
| `MAIL_MAILER` | Yes | `log` | The Laravel mailer that actually sends: `resend`, `ses`, `postmark`, `smtp`, `sendmail`, `mailbox`, `log`. |
| `OUTBOX_PROVIDER` | No | falls back to `MAIL_MAILER`, then `mailbox` | Labels deliveries and selects the Resend paths (batch sending + rate limiter) when set to `resend`. Normally set equal to `MAIL_MAILER`. |
| `RESEND_API_KEY` | For Resend | — | Resend API key, used by the mailer, the batch sender, and the webhook-verification client. |
| `RESEND_WEBHOOK_SECRET` | For Resend analytics | — | Svix/Resend webhook signing secret used to verify incoming events. |
| `RESEND_BASE_URL` | No | `https://api.resend.com` | Resend API base URL. Override for a mock/self-hosted endpoint in tests. |
| `OUTBOX_RESEND_BATCH` | No | `true` | When `OUTBOX_PROVIDER=resend`, send through Resend's batch endpoint. Set `false` to fall back to one queued mailable per recipient. |
| `OUTBOX_RESEND_BATCH_SIZE` | No | `100` | Recipients per batch call. Clamped to Resend's hard limit of 100 — it can only be lowered, never raised. |
| `OUTBOX_RESEND_BATCH_PAUSE_MS` | No | `200` | Pause between batch calls, in milliseconds. 200 ms keeps the proxy at ~5 calls/second, under Resend's 10 req/s team limit. |
| `POSTMARK_API_KEY` | For Postmark | — | Postmark server token. |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` | For SES | region `us-east-1` | Amazon SES credentials. |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_SCHEME` | For SMTP | — | SMTP transport settings. |
| `QUEUE_CONNECTION` | No | `database` | Queue backend. A worker **must** be running (see [Deployment](deployment.md)). |
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

Batch sending is on by default for Resend, so the snippet above already uses it — the `OUTBOX_RESEND_BATCH*` and `RESEND_BASE_URL` variables only need setting to tune or disable it.

Notes:

- `MAILGUN_API_KEY` is the shared key Ghost uses as its Mailgun API key. `MAIL_MAILER` selects how mail is actually delivered; `OUTBOX_PROVIDER` should match it.
- `RESEND_API_KEY` is used by Laravel's Resend mailer and the batch sender.
- `RESEND_WEBHOOK_SECRET` is the Svix/Resend webhook signing secret.

## Provider setup

### Resend (full analytics)

1. Create a Resend API key and set `RESEND_API_KEY`.
2. Add a webhook in the Resend dashboard pointing to:

   ```text
   https://newsletter-proxy.domain.tld/api/webhook/resend
   ```

   Subscribe to at least the `email.delivered`, `email.opened`, `email.bounced`, `email.complained`, `email.failed`, and `email.delivery_delayed` events.
3. Copy the webhook's signing secret into `RESEND_WEBHOOK_SECRET`. The proxy rejects any webhook whose Svix signature does not verify.
4. On your Resend domain, **enable open tracking**. **Do not enable click tracking** — Ghost rewrites and tracks links itself before handing the HTML to the proxy, so provider-side click tracking would double-rewrite the links and break Ghost's click analytics.

**Throttling.** On the batch path (the default), the proxy pauses `OUTBOX_RESEND_BATCH_PAUSE_MS` (200 ms) between batch calls — ~5 calls/second, each up to 100 recipients. On the per-recipient fallback path (`OUTBOX_RESEND_BATCH=false`), individual sends are rate-limited to 5/second. Both stay under Resend's default 10 req/s ceiling.

### SES, Postmark, SMTP, Sendmail

Set `MAIL_MAILER` (and `OUTBOX_PROVIDER`) to the provider and supply its credentials from the table above. These providers **send** correctly, but there is currently no webhook ingestor for them, so open/bounce/complaint analytics will not be reported back to Ghost — only the initial *accepted* state and local send failures are tracked. Use Resend if analytics parity matters.

### Mailbox (local testing)

Set `MAIL_MAILER=mailbox` to capture newsletters locally without sending anything, using [Mailbox for Laravel](https://github.com/RedberryProducts/mailbox-for-laravel). This is the default outside production.

## See also

- [How it works](how-it-works.md) — the send and analytics flows.
- [Ghost setup](ghost-setup.md) — the Ghost-side configuration.
- [Deployment](deployment.md) — Docker, reverse proxy, and the queue worker.
