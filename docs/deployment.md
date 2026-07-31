# Deployment

[← Back to the README](../README.md)

## Docker Compose

```bash
cp .env.example .env
# Edit .env with your configuration (at minimum APP_KEY via key:generate,
# APP_URL, MAILGUN_API_KEY, MAIL_MAILER, and your provider credentials)

docker compose up -d --build
docker compose exec app php artisan key:generate
```

The app is available at `http://localhost:8080`.

Good to know about the image:

- The container runs nginx, PHP-FPM, **and the queue worker** together (via a Procfile run by hivemind), so you do not need to start a separate worker for Docker deployments.
- Database **migrations run automatically** on container start (`RUN_MIGRATIONS=true` by default). You only need `key:generate` if `APP_KEY` is empty.
- The image defaults to a **SQLite** database persisted in the `data` volume (`/data/database.sqlite`). The bundled `docker-compose.yml` also starts a PostgreSQL service; to actually use it, set `DB_CONNECTION=pgsql` and the `DB_*` variables in `.env`.
- An unauthenticated health endpoint is exposed at `/up` for load balancers and orchestrators.

## Reverse proxy

Terminate TLS in front of the container and forward the proxy's own origin root to port `8080`. Because Ghost discards the path of `baseUrl` (see [Ghost setup](ghost-setup.md)), the proxy must own the whole origin — a dedicated subdomain is the simplest setup. Forward `X-Forwarded-Proto`/`X-Forwarded-For`; the bundled nginx already trusts private ranges (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) for real-IP resolution.

## Queue worker

A running queue worker is **mandatory**, not optional — both the outbound send and Resend webhook processing depend on it. If the worker is not running, requests will pile up as *queued* on the dashboard and nothing is delivered. In Docker this is handled for you; on a bare-metal or Forge-style deploy, run `php artisan queue:work` under a supervisor.

## Local setup

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

## Dashboard

The proxy includes a Laravel + Inertia dashboard for inspecting newsletter requests, attempts, per-recipient deliveries, and webhook events, and for retrying failed requests.

- `/dashboard` — delivery summary, failure/complaint rates, a 30-day timeline, suppression signals, and a scrollable request log with retry.
- `/health` — a live status board of the proxy's configuration and delivery signals.
- The dashboard is behind login. Registration is only available while **no user exists yet**, so the first visit after deploy lets you create the single admin account and then locks registration.

## See also

- [Configuration](configuration.md) — the environment variables referenced above.
- [Ghost setup](ghost-setup.md) — why the proxy needs its own origin root.
- [Troubleshooting](troubleshooting.md) — "Requests stuck as Queued" and other runtime symptoms.
