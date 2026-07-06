# QDS Development Guide

Application-level conventions for the QDS Laravel codebase. **Product facts
are canonical in [`docs/`](docs/README.md)** — entities and envelopes in the
[data model](docs/30-data-model/00-data-model.md), enums in the
[glossary](docs/00-meta/03-glossary.md), write-ownership in the
[ownership matrix](docs/70-shared/00-ownership-matrix.md), providers in the
[data-source matrix](docs/40-integrations/00-data-source-matrix.md),
decisions in the [decision log](docs/05-decisions/decision-log.md). This file
never restates them; it maps them onto code.

## Modular monolith layout

```
app/
├── Models/                      # Framework-level models (User)
├── Actions/Fortify/             # Auth actions (password reset rules)
├── Providers/                   # App + Fortify providers
├── Modules/                     # The exactly-three product modules
│   ├── Monitoring/              #   SVC-Monitoring   (Module 1, P1)
│   ├── Discovery/               #   SVC-Discovery    (Module 2, P2)
│   └── CRM/                     #   SVC-CRM          (Module 3, P3)
│       ├── CrmServiceProvider.php
│       ├── routes.php
│       ├── Policies/            #   authorization for module-owned entities
│       └── Livewire/            #   module UI components
├── Platform/                    # Shared platform services (no module owns them)
│   ├── PlatformServiceProvider.php
│   ├── Ingestion/               #   SVC-Ingestion + SourceRegistry (SRC-*)
│   ├── Enrichment/              #   SVC-EnrichmentAI
│   ├── Snapshots/               #   SVC-SnapshotScheduler (+ console command)
│   ├── Analytics/               #   SVC-Analytics (+ rollup refresh command)
│   └── Export/                  #   SVC-Export
└── Shared/                      # Cross-cutting kernel
    ├── Enums/                   #   the canonical ENUM-* sets as PHP enums
    ├── ValueObjects/            #   Provenance, ConfidenceAssessment,
    │                            #   MetricValue, ReachEstimate (envelopes)
    ├── Casts/                   #   AsValueObject (JSON envelope columns)
    ├── Authorization/           #   PermissionsCatalog
    ├── Audit/                   #   AuditLog + AuditLogger
    ├── Security/                #   BlindIndex (keyed lookup hashes)
    ├── Livewire/Concerns/       #   WithDataTable
    ├── Http/                    #   middleware, health controller, responses
    └── Exceptions/              #   NotYetImplemented
```

Rules of the structure:

- **One write path per entity.** A model/table is written only by code in its
  write-owner module (per the ownership matrix). Other modules read, or
  propose via a cross-module contract (`XMC-*`) service interface.
- **Platform services are shared**, not module-owned. They expose contracts
  (`app/Platform/*/Contracts`) bound in `PlatformServiceProvider`; while a
  phase hasn't delivered an implementation, the binding is a `Pending*` class
  that throws `NotYetImplemented` — call-sites never silently no-op.
- **Envelopes are embedded value objects** stored as JSON columns via
  `AsValueObject` casts. Their constructors enforce doctrine (registered
  `SRC-*` provenance, non-empty signals, reach-tier constraints), which is
  the persistence-layer enforcement P0 requires.
- No repository-pattern indirection, no per-module composer packages, no
  events where a method call does — Laravel-native first.

### Creating a new module surface (checklist)

1. Domain code in `app/Modules/<Module>/…` (models the module write-owns,
   actions, queries, policies, Livewire components).
2. Routes in the module `routes.php`, gated with `auth` + the module's
   permission; register in the module service provider.
3. Policies registered in the module provider (`Gate::policy`).
4. Livewire components registered with a `<module>.` name prefix.
5. Sidebar entry in `resources/views/layouts/sidebar.blade.php` behind
   `@can(...)`.
6. Feature tests per behaviour; factory data is always synthetic.

## Livewire conventions

- Class components under `app/Modules/<Module>/Livewire/…`, views under
  `resources/views/livewire/<module>/…`, registered as `<module>.<name>`.
- **Authorize in `mount()` and in every mutating action** (`$this->authorize`)
  — route middleware alone is not enough, Livewire actions are separate HTTP
  entry points.
- Data tables use `App\Shared\Livewire\Concerns\WithDataTable`: define
  `sortableColumns()` (the ORDER BY whitelist — sort/page/filter state is
  user-controlled query-string input and must be validated on read) and
  `currentPageIds()`; build the query with `applySort()` + `perPage()`.
- Validation is always server-side `rules` via `$this->validate()`.
- User feedback via `$this->dispatch('notify', type: ..., message: ...)` —
  the shell's toast area listens for it.
- Destructive actions require an explicit confirmation step (see the delete
  flow in `UsersIndex`).
- The reference implementation for all of the above is
  `app/Modules/CRM/Livewire/Users/UsersIndex.php` +
  `resources/views/livewire/crm/users-index.blade.php`.

### Deferred data in the UI

Anything mapped to a `DEF-*` renders the literal **"unavailable"** state via
`<x-states.unavailable reason="… (DEF-00N)" />` — never an empty cell, never
zero, never a fabricated value
([deferred register](docs/20-cross-cutting/01-deferred-register.md)).

## Authorization model

- Roles are exactly the six `ENUM-RoleName` values, seeded by
  `RolePermissionSeeder`. **Every user holds exactly one role** (ENT-User) —
  always `syncRoles([$role])`, never `assignRole()` on top.
- Permission names live in `App\Shared\Authorization\PermissionsCatalog`,
  with each entry traceable to a documented fact.
  **Documentation gap (flagged):** the docs define role *names* and the
  CLIENT_VIEWER/ADMIN behaviours, but no canonical fine-grained permission
  matrix. The catalog stays minimal until REQ-M3-012 (P3) specifies it —
  propose additions through the decision log rather than inventing them.
- `CLIENT_VIEWER` holds only `reports.view-approved`. Brand-level scoping
  ("only their own brands") requires ENT-Client/ENT-Brand and a report
  entity — the report policy hook is marked in `routes/web.php` and MUST be
  implemented with the P3 report surface before any real report data ships.

## Database & migrations

- PostgreSQL only (Neon in production — plain Postgres locally; **no
  TimescaleDB, no pg_cron** per ADR-0013).
- OLTP migrations in `database/migrations/`. Analytics DDL (`FACT-*`,
  `DIM-*`, `ROLLUP-*`) will live in `database/migrations/analytics/` and use
  native declarative partitioning; rollups are refreshed by
  `qds:refresh-rollups` (Laravel scheduler), never by the database.
- Bigint identity PKs (Laravel default — the canonical data model does not
  mandate UUID/ULID). Foreign keys with explicit `on delete` behaviour,
  unique constraints and indexes at creation time. `jsonb` only for genuinely
  flexible structures (envelopes, audit context). Soft deletes only where a
  documented workflow needs recoverability.
- Append-only tables (audit logs, later analytics facts) are never updated.

### Domain migration plan (P0 remainder → P3)

Only foundation tables exist today (users, sessions, cache, jobs,
notifications, permission tables, audit_logs). The full `ENT-*` set is a P0
exit criterion and is migrated in **dependency order**; write-owner per the
[ownership matrix](docs/70-shared/00-ownership-matrix.md) (this table adds no
new facts — shapes come from the
[data model](docs/30-data-model/00-data-model.md)):

| Wave | Tables (snake_case of ENT-*) | Write-owner | Depends on |
| --- | --- | --- | --- |
| 1 | `creators` | M3 | — |
| 1 | `platform_accounts` | M3 | creators |
| 2 | `clients` → `brands` → `products` | M3 | — (hierarchy in order) |
| 3 | `content_items`, `stories` | M1 | platform_accounts |
| 3 | `comments` (defined, **unpopulated** — DEF-005) | M1 | content_items |
| 4 | `monitored_subjects` | M1 | creators, campaigns (nullable) |
| 4 | `metric_snapshots` | M1 | platform_accounts, content_items |
| 5 | `campaigns` | M3 | brands, creators |
| 5 | `seeding_campaigns`, `shipments` | M3 | campaigns, brands, products, creators |
| 6 | `mentions`, `recognition_detections`, `sentiment_analyses` | M1 | monitored_subjects, content_items, stories, campaigns |
| 7 | `sector_classifications`, `geo_attributions`, `authenticity_assessments`, `suitability_scores`, `shortlists` | M2 | creators |
| 8 | `contacts`, `brand_preferences`, `communication_logs`, `document_attachments`, `tasks` | M3 | creators, campaigns, users |
| 9 | analytics: `dim_*`, `fact_*` (partitioned), rollups | SVC-Analytics | waves 1–8 |

Naming: table = plural snake_case of the entity; envelope columns keep the
data-model field name (`provenance`, `classification`, `assessment`) as
`jsonb` with an `AsValueObject` cast; enum columns are `string` + PHP-enum
cast (values validated by the enum, not a DB check, so glossary changes stay
one-file).

**Before wave 8 (personal data):** the encryption plan below must be applied
and the flagged documentation gap resolved.

## Personal-data encryption

Foundation shipped in this skeleton:

- Laravel's `encrypted` cast family (AES-256-CBC + HMAC, authenticated) for
  at-rest application-level encryption; keys from `APP_KEY`, rotation via
  `APP_PREVIOUS_KEYS` (no data loss — Laravel tries previous keys on
  decrypt; re-encryption happens on save).
- `App\Shared\Security\BlindIndex` — keyed HMAC-SHA256 lookup hashes
  (`QDS_BLIND_INDEX_KEY`, independent of APP_KEY) for exact matching on
  encrypted fields. Never a plain unsalted hash; never a plaintext shadow
  column; encrypted columns never appear in ORDER BY / LIKE / ordinary
  indexes.
- Audit events for sensitive changes via `AuditLogger` (never containing the
  decrypted values); `context` is identifiers only.

Field-level plan for the domain waves (entities from the
[data model](docs/30-data-model/00-data-model.md); GDPR doctrine DP-005):

| Entity | Field | Classification | Storage | Exact lookup? | Access / audit | Retention & deletion |
| --- | --- | --- | --- | --- | --- | --- |
| ENT-Contact | `email` | personal | `encrypted` cast | yes → `email_index` (BlindIndex) | CRM roles via policy; reads/exports audited | data-subject deletion (AC-M3-006); retention limits P4 |
| ENT-Contact | `phone` | personal | `encrypted` cast | yes → `phone_index` (digits-normalized) | same | same |
| ENT-Contact | `postalAddress` | personal (shipment recipient) | `encrypted` cast | no | same; shipment labels decrypt on demand | same |
| ENT-Contact | `preferredChannel` | low-sensitivity | plain | — | policy | deleted with contact |
| ENT-CommunicationLog | `summary` | personal (may contain PI) | `encrypted` cast | no (no full-text search in v1) | CRM roles; policy | deleted/anonymized with creator |
| ENT-BrandPreference | `notes` | personal (private notes) | `encrypted` cast | no | CRM roles | deleted with creator |
| ENT-Creator | `displayName` | public-figure identifier (publicly sourced) | plain | needed for search/sort | all staff | data-subject deletion cascades |
| ENT-User | `email` | staff personal, login identity | plain (required for auth lookup/unique) | native unique index | ADMIN-managed | account deletion removes it |
| ENT-User | `display_name` | staff personal | plain | search/sort | ADMIN-managed | same |
| ENT-DocumentAttachment | stored file | may contain PI (contracts) | private disk, non-public storage; validated uploads | no | policy + audited downloads | deleted with parent record |
| Client contact persons (if modelled beyond ENT-Client.name) | any | personal | `encrypted` cast | per field | policy | per client contract |

Non-negotiables (enforced in review):

- Personal data never appears in logs, analytics facts/rollups, queue
  payloads (pass ids, not values), cache keys, URLs/query strings, exception
  reports, or external AI requests — use internal identifiers.
- Factories/seeders: synthetic data only. Production data is never copied to
  dev/test/preview.
- Decryption only inside authorized service/model paths after a policy
  check; encrypted DB values are never rendered raw in the UI.
- Exports and reads of personal data create audit events (without values).

**Documentation gap (flagged, do not resolve silently):** the canonical data
model does not tag fields as personal/sensitive. The classification above is
an application-level judgement pending a documentation change — propose a
per-field sensitivity marker in the data model via the decision log before
implementing wave 8.

## Doctrine quick-reference for coders

- Every externally-sourced record: `Provenance` envelope, `source` must be a
  `SourceRegistry` id (DP-002; enforced by the value object).
- Every inferred value: `ConfidenceAssessment` (DP-003); AI outputs start at
  `AI_ASSESSED`; low-confidence → review queue (DP-004).
- Every metric: `MetricValue`/`ReachEstimate` with a tier — never a bare
  number; reach is never PUBLIC/DERIVED (DP-001; enforced).
- Providers: `SourceRegistry` only — adding one requires an ADR (DP-006).
- Deferred (`DEF-*`) surfaces render "unavailable".

## Remaining P0 work

Tracked against the P0 exit criteria in the
[roadmap](docs/80-delivery/00-roadmap.md#p0--foundation):

1. Full `ENT-*` domain migrations + models (waves above) with envelope casts.
2. `SVC-Ingestion` connectors for the frozen sources (Apify IG actors,
   Clockworks TikTok, YouTube Data API v3) writing raw records + Provenance.
3. `SVC-SnapshotScheduler` implementation over the connectors
   (then set `QDS_SNAPSHOTS_ENABLED=true`).
4. Analytics star schema (`database/migrations/analytics/`, partitioned
   facts, rollup catalog) + real `AnalyticsService`
   (then `QDS_ANALYTICS_ROLLUP_REFRESH_ENABLED=true`).
5. Production containerization (PHP-FPM/nginx/worker/scheduler images) and
   CI pipeline (pint + phpstan + tests + build already runnable locally).
6. Object storage wiring (Hetzner Object Storage / R2) for media & documents.
