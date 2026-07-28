# Ghost setup

[← Back to the README](../README.md)

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

- `baseUrl` must be the proxy URL and should include the trailing slash.
- `apiKey` must match `MAILGUN_API_KEY` in the proxy `.env`.
- `domain` should be the Ghost newsletter sending domain. The proxy scopes events per `domain`.

## Two Ghost gotchas

Both come from how Ghost reads this config — worth knowing before you deploy:

- **Ghost keeps only the *origin* of `baseUrl`.** Ghost runs `new URL(baseUrl).origin` and throws the path away, so the proxy must live at the **root of its own origin** (e.g. `https://newsletter-proxy.domain.tld/`) and serve the literal `/v3/...` paths there. You **cannot** mount it under a sub-path such as `https://domain.tld/mailgun/`. Give it its own subdomain (or its own host) behind your reverse proxy.
- **A partial `bulkEmail.mailgun` block silently shadows the admin UI.** If *any* truthy `bulkEmail.mailgun` object exists in Ghost's config file, it fully overrides the Mailgun settings entered in **Settings → Email newsletters** — even if the config block is incomplete. Configure Mailgun in *one* place (the config file is recommended here) to avoid a half-applied config that silently sends nowhere.

## Transactional email is separate

Transactional email (member sign-in links, password resets, etc.) is a **separate** seam in Ghost and does not go through this proxy. If you are moving off Mailgun entirely, point Ghost's `mail` (SMTP) transport straight at your provider's SMTP endpoint — for Resend, that's [Resend SMTP](https://resend.com/docs/send-with-smtp). This proxy is bulk-newsletter-only.

## See also

- [Configuration](configuration.md) — the proxy `.env` side that `apiKey` and `domain` must line up with.
- [Deployment](deployment.md) — the reverse-proxy constraint that follows from the origin-root gotcha.
- [Troubleshooting](troubleshooting.md) — "Set up Mailgun to start sending newsletters!" and other symptoms.
