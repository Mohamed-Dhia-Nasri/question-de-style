# Question de Style (QDS)

Internal influencer-intelligence platform for a DACH influencer-marketing
agency. Exactly three modules — **Monitoring & Reporting**, **Discovery**,
**CRM & Seeding** — on one Laravel modular monolith.

> **Canonical documentation lives in [`docs/`](docs/README.md) and wins over
> everything else.** Read [`docs/AGENTS.md`](docs/AGENTS.md) and the reading
> order in [`docs/00-meta/00-index.md`](docs/00-meta/00-index.md) before
> changing code. This README covers only how to run and develop the
> application; product facts (entities, enums, sources, roles, decisions) are
> never restated here.

Current state: **P0 foundation skeleton** (see
[`docs/80-delivery/00-roadmap.md`](docs/80-delivery/00-roadmap.md) and
[Remaining P0 work](DEVELOPMENT.md#remaining-p0-work)).

## Stack

Fixed by [ADR-0012](docs/05-decisions/decision-log.md#adr-0012) and
[ADR-0013](docs/05-decisions/decision-log.md#adr-0013):

- Laravel 12 (PHP ≥ 8.2), Blade + **Livewire 4** + Alpine.js (bundled with
  Livewire) + **Tailwind CSS v4**, TailAdmin design system — no Filament, no
  separate frontend app.
- **PostgreSQL only.** Production: **Neon** (serverless Postgres, EU) — which
  supports **neither TimescaleDB nor pg_cron**; analytics facts use native
  declarative partitioning and rollups are refreshed by the Laravel scheduler.
- spatie/laravel-permission for roles/permissions; Laravel Fortify (headless)
  for authentication with hand-built TailAdmin views.
- Deployment target: Docker on Hetzner (EU); the database is Neon, not a
  container.

## Requirements

- PHP 8.2+ with Composer 2
- Node 18+ with npm
- Docker (for local PostgreSQL)

## Quickstart

```bash
# 1. Local services (PostgreSQL 17 on 127.0.0.1:5433; --wait blocks until healthy)
docker compose up -d --wait postgres
docker exec qds-postgres psql -U qds -c "CREATE DATABASE qds_test;"   # once, for the test suite

# 2. App setup (.env.example already carries the local docker credentials)
composer install
cp .env.example .env
php artisan key:generate
# Generate a blind-index key and paste it into .env as QDS_BLIND_INDEX_KEY:
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"

# 3. Database
php artisan migrate
php artisan db:seed        # canonical roles/permissions + local dev accounts

# 4. Frontend
npm install
npm run build

# 5. Run everything (server + queue worker + logs + vite)
composer dev
```

Local logins (non-production only): `admin@qds.test` / `password` (ADMIN) and
`client@qds.test` / `password` (CLIENT_VIEWER).

## Commands

| Task | Command |
| --- | --- |
| Dev server + queue + logs + Vite | `composer dev` |
| Tests (PostgreSQL `qds_test`) | `composer test` |
| Code style (Pint) | `composer lint` / `composer lint:check` |
| Static analysis (Larastan) | `composer analyse` |
| Frontend dev / build | `npm run dev` / `npm run build` |
| Migrations | `php artisan migrate` |
| Seed roles/permissions | `php artisan db:seed --class=RolePermissionSeeder` |
| Queue worker | `php artisan queue:work` |
| Scheduler (local loop) | `php artisan schedule:work` |
| Snapshot capture (gated) | `php artisan qds:capture-snapshots` |
| Analytics rollup refresh (gated) | `php artisan qds:refresh-rollups` |

## Health & observability

- `GET /up` — liveness (Laravel built-in).
- `GET /health` — database check + build metadata (`QDS_BUILD_SHA`,
  `QDS_BUILD_TIME`); returns 503 when degraded.
- Every response carries an `X-Request-Id`; the same id is attached to all log
  lines for that request (`Log::withContext`).
- <a id="queues"></a>**Queues:** local default is the `database` driver —
  pending work is visible in the `jobs` table, failures in `failed_jobs`
  (`php artisan queue:failed` / `queue:retry`). Production uses Redis +
  Horizon (same code path; set `QUEUE_CONNECTION=redis`). A growing `jobs`
  count with an idle worker is the primary queue-health signal until Horizon
  ships.
- Never log personal data, secrets, tokens, or raw provider payloads.

## Testing

`composer test` runs the suite against the real PostgreSQL `qds_test`
database (so `ilike`, `jsonb`, and the actual migrations are exercised —
there is no SQLite fallback by design). Tests never call external providers.

## Deployment notes (production)

Per [ADR-0013](docs/05-decisions/decision-log.md#adr-0013):

- App tier containerized (PHP-FPM, nginx, Redis, Horizon worker, scheduler)
  on Hetzner (EU); full Compose/production images are remaining P0 infra work.
- Database: Neon Postgres (EU/Frankfurt), `DB_SSLMODE=require` (TLS is
  mandatory), scale-to-zero disabled on the production branch; Neon branching
  for dev/preview databases. Neon manages encrypted backups.
- `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`.
- Secrets (APP_KEY, `APP_PREVIOUS_KEYS`, DB credentials,
  `QDS_BLIND_INDEX_KEY`) come from the deployment environment — never from
  the repository. Key rotation: prepend the old key to `APP_PREVIOUS_KEYS`,
  set a new `APP_KEY`; see [DEVELOPMENT.md](DEVELOPMENT.md#personal-data-encryption).
- Scheduler: one cron entry — `* * * * * php artisan schedule:run`.
- Production personal data is never copied to local/test/preview
  environments; those use synthetic data only.

## Repository layout

See [DEVELOPMENT.md](DEVELOPMENT.md) for the modular-monolith structure,
module and Livewire conventions, the domain migration plan, and the
personal-data encryption plan.
