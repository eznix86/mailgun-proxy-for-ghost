# Troubleshooting

[← Back to the README](../README.md)

**Ghost shows "Set up Mailgun to start sending newsletters!"** — Ghost thinks Mailgun is not configured. Check that `bulkEmail.mailgun` in Ghost's config file has all three of `baseUrl`, `apiKey`, and `domain`, and remember that a partial block there overrides the admin UI (see [Ghost setup](ghost-setup.md)).

**`401 Unauthorized` from the proxy on send** — the API key mismatched. Ghost authenticates as HTTP Basic `api` : `<apiKey>`; the proxy compares `<apiKey>` against `MAILGUN_API_KEY`. Make sure the two match exactly. A `503` with "Mailgun proxy not configured" means `MAILGUN_API_KEY` is empty on the proxy.

**Requests stuck as "Queued" on the dashboard** — no queue worker is running. Start `php artisan queue:work` (or check the worker process in Docker). See [Deployment](deployment.md).

**Newsletters send but opens/bounces never appear in Ghost** — you are either not on Resend, or the webhook is not reaching the proxy. Confirm the Resend webhook points at `/api/webhook/resend`, that `RESEND_WEBHOOK_SECRET` matches the webhook's signing secret (a mismatch yields `401`/`503` on the webhook and no stored events), and that open tracking is enabled on the Resend domain.

**Events reach the proxy but Ghost still shows nothing** — Ghost matches events to messages by the `email-id` variable. Confirm the proxy is reachable at its **origin root** serving `/v3/...` (not under a sub-path), since Ghost discards any path in `baseUrl`.

**Webhook `401 Invalid webhook signature`** — the Svix signature failed. Verify `RESEND_WEBHOOK_SECRET` and that your reverse proxy is not altering the raw request body (signature verification runs over the exact bytes received).

**Clicks tracked twice / links look double-wrapped** — click tracking is enabled on the Resend domain. Turn it off; Ghost rewrites and tracks links itself, so provider-side click tracking double-rewrites them. Leave open tracking on.

**Ghost's "remove from suppression list" does nothing on the provider** — expected. Ghost's suppression-removal calls (`DELETE /v3/{domain}/{bounces|complaints|unsubscribes}/{address}`) are acknowledged as **logged no-ops**: the proxy returns a Mailgun-shaped `200` (e.g. `{"message":"Bounce has been removed","address":"..."}`) so Ghost is satisfied, but it keeps no suppression list of its own and Resend exposes no API to clear one. Each call leaves a log line so an operator can clear the suppression manually in the Resend dashboard:

```text
Acknowledged Mailgun suppression removal as a no-op.  {"type":"bounces","address":"user@example.com","domain":"domain.tld"}
```

## See also

- [How it works](how-it-works.md) — the send and analytics flows behind these symptoms.
- [Configuration](configuration.md) — the variables named above.
- [Ghost setup](ghost-setup.md) · [Deployment](deployment.md)
