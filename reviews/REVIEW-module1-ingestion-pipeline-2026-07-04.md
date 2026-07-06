<!--
  Deep-Review Handoff — Module 1 ingestion pipeline (Prompt 2)
  Written by the IMPLEMENTATION model. Consumed by a SEPARATE review model.
-->

# Deep Review — Module 1 Ingestion, Providers, Queues & Snapshots

- **review_status:** PENDING_REVIEW
- **outcome:** —
- **implemented_by:** claude-fable-5
- **implementation_date:** 2026-07-04
- **reviewer:** unassigned
- **deep_review_trigger:** canonical architecture (SVC-Ingestion / SVC-SnapshotScheduler go live); external-provider integration; personal-data-adjacent media storage; large feature step (P1 step 2/4)

---

## 1. Implementation summary

Implemented the full Module 1 ingestion pipeline: provider contracts + adapters for the frozen SRC-* registry (Apify Instagram profile/post/reel/story actors, Clockworks TikTok, YouTube Data API v3), normalization into documented DTOs with mandatory Provenance, idempotent persistence keyed on `(platform, external_id)`, roster-only monitoring cycles as queue jobs with retries/backoff/rate-limit handling, story media archival into private object storage with short-lived signed URL access, the real `SVC-SnapshotScheduler` (account + content MetricSnapshots from ingested data), and production-grade External API Monitoring (per-call telemetry, quarantine, provider health, deduplicated alerts, redacted response sampling). **Out of scope:** SVC-EnrichmentAI (mentions, sentiment, recognition, EMV), dashboards/exports, comment collection (DEF-005), open-web listening (DEF-006), analytics facts/rollups.

## 2. Changed files

**Provider layer (`app/Platform/Ingestion/`)**
- `Contracts/{ProfileProvider,ContentProvider,StoryProvider}.php` — new adapter contracts per capability.
- `Contracts/IngestionService.php` — extended with `startMonitoringCycle()`; docs updated (implementation now live).
- `Contracts/PlatformAccountProfileSync.php` — new XMC-style contract: ingestion → CRM profile write path.
- `DTO/{ProfileData,ContentData,StoryData,RejectedRecord,ProviderResponse,NormalizedBatch}.php` — normalized DTOs.
- `Http/{ApifyClient,YouTubeClient,RawJsonResponse}.php` — HTTP clients; error classification; token only in header/env.
- `Normalization/{Extract,RecordRejected,NormalizesItems}.php` — typed field extraction, per-item rejection, batch loop.
- `Providers/ProviderResolver.php` — closed Platform→adapter map (mirrors matrix §2.1).
- `Providers/Instagram/{Profile,Post,Reel,Story}Adapter.php`, `Providers/TikTok/{Profile,Content}Adapter.php`, `Providers/YouTube/{Profile,Content}Adapter.php` — the eight adapters.
- `DefaultIngestionService.php` — real SVC-Ingestion (replaces PendingIngestionService, deleted).

**Jobs & cycle (`app/Platform/Ingestion/Jobs/`)**
- `RunMonitoringCycleJob.php` — roster fan-out + duplicate-cycle prevention (ShouldBeUnique + fresh-RUNNING guard).
- `PollMonitoredAccountJob.php` — per-account fan-out, WithoutOverlapping.
- `{IngestProfileJob,IngestContentJob,IngestStoriesJob}.php` — fetch/normalize/persist, per-provider telemetry, partial-failure handling.
- `ArchiveStoryMediaJob.php` — CDN download → private disk; expired-media short-circuit.
- `RefreshIngestionStatusJob.php` — stale cycles, stale-data warnings, story-polling risk.
- `Concerns/IngestionJobBehaviour.php` — backoff, rate-limit release, cycle-slot bookkeeping, failed() alert.

**Observability (`app/Platform/Ingestion/{Models,Observability,Support}/`)**
- Models: `ProviderCall`, `ProviderHealthState`, `IngestionAlert`, `QuarantinedRecord`, `ProviderResponseSample`, `IngestionCycle`.
- Services: `ProviderCallRecorder` (single telemetry sink), `ProviderHealthService` (health view incl. p95/success-rate/staleness), `AlertService` (fingerprint-deduplicated), `ResponseSampler` (redacted, short retention).
- Support: `ErrorCategory`, `CallOutcome`, `ProviderStatus`, `CycleStatus`, `AlertType`, `PayloadRedactor`.
- `Observability/Policies/ProviderResponseSamplePolicy.php` — samples gated on `audit.view` (ADMIN-only).

**Persistence**
- `Persistence/{ContentItemPersister,StoryPersister,PersistenceResult}.php` — idempotent upserts + human-override preservation.
- `app/Modules/CRM/Services/IngestedProfileSync.php` — CRM-owned implementation of the profile-sync contract.

**Snapshots (`app/Platform/Snapshots/`)**
- `DatabaseSnapshotScheduler.php` — real SVC-SnapshotScheduler (replaces PendingSnapshotScheduler, deleted).
- `Jobs/CreateSnapshotsJob.php` — queued entry point.

**Module 1 surface**
- `app/Modules/Monitoring/Http/Controllers/StoryMediaController.php` + `app/Modules/Monitoring/routes.php` — signed-URL issue/stream endpoints.
- `app/Modules/Monitoring/Models/{ContentItem,Story}.php` — `human_overrides` attribute; @property docblocks.

**Schema / config / wiring**
- `database/migrations/2026_07_04_200001_create_ingestion_operations_tables.php` — 6 operational tables.
- `database/migrations/2026_07_04_200002_add_human_overrides_to_content_items_and_stories.php`.
- `app/Shared/ValueObjects/MetricValue.php` — optional `metric` label (deviation, §4).
- `config/qds.php` (ingestion + snapshot sections), `config/services.php` (apify/youtube), `config/filesystems.php` (private `media` disk), `.env.example`, `routes/console.php` (4 new schedule entries), `app/Platform/PlatformServiceProvider.php` (live bindings, commands, sample policy).
- Console: `RunMonitoringCycleCommand`, `RefreshIngestionStatusCommand`, `PruneIngestionDataCommand`, `ProviderHealthCommand`.

**Tests** — `tests/Feature/Ingestion/*` (6 files), `tests/Feature/Snapshots/SnapshotCreationTest.php`, `tests/Unit/Ingestion/PayloadRedactorTest.php`, `tests/Support/FakesProviderResponses.php`, `tests/Fixtures/providers/*.json` (8 synthetic fixtures), `tests/Feature/PlatformBoundariesTest.php` (updated: scheduler now implemented).

## 3. Canonical documents relied upon

- `docs/40-integrations/00-data-source-matrix.md` (`SRC-*`) — provider registry, capability→source matrix (§2.1), raw→domain mapping (§4), snapshot provenance note (§5).
- `docs/50-modules/module-1-monitoring.md` (`REQ-M1-*`, `AC-M1-*`) — REQ-M1-001/003/004/005/007; AC-M1-001/004/005/008/021.
- `docs/30-data-model/00-data-model.md` — ENT-ContentItem/Story/MetricSnapshot/PlatformAccount/MonitoredSubject shapes; Provenance/MetricValue envelopes.
- `docs/70-shared/00-ownership-matrix.md` — M1 write-set; PlatformAccount/Creator owned by M3 (drove the XMC profile-sync contract).
- `docs/20-cross-cutting/00-data-principles.md` (`DP-001/002/005/006`) — tiering, provenance-mandatory, GDPR/retention, stack lock.
- `docs/20-cross-cutting/01-deferred-register.md` — DEF-002 (no contact extraction), DEF-005 (no comments), DEF-006 (no open-web).
- `docs/05-decisions/decision-log.md` — ADR-0001/0002 (frozen stack, TikTok=Apify), ADR-0003 (own-DB snapshots), ADR-0011 (roster-first), ADR-0013 (object storage, Neon).
- `docs/60-architecture/00-system-architecture.md` (`SVC-*`) — L2/L3 responsibilities; SnapshotScheduler reads existing accounts/content from DB.
- `docs/80-delivery/00-roadmap.md` — P1 active; P0 exit criteria for connectors/scheduler.

## 4. Known deviations / open conflicts

1. **`MetricValue.metric` optional label** (`app/Shared/ValueObjects/MetricValue.php`): the canonical envelope defines only `amount` + `tier`; an anonymous metric list cannot support follower-growth reconstruction (AC-M1-021) or "update mutable public metrics". Added as optional/backward-compatible. **Needs a data-model doc amendment** (same class as the `external_id` deviation from step 1 — see `memory/module1-schema-deviations`).
2. **`human_overrides` column** on `content_items`/`stories` — not in canonical field tables; required by "preserve human corrections during subsequent ingestion runs" (DP-004). Needs the same amendment.
3. **Operational tables are undocumented persisted shapes**: `provider_calls`, `provider_health_states`, `ingestion_alerts`, `quarantined_records`, `provider_response_samples`, `ingestion_cycles`. They hold no domain facts (sanitized ops metadata only), but the data-model doc claims to be "the single home for the shape of every persisted thing" — propose an "operational infrastructure" register or explicit doc exclusion.
4. **Missing canonical decision — poll & snapshot cadence.** No document fixes monitoring-cycle or snapshot cadence. Made configurable (`qds.ingestion.cycle_cron`, `story_cycle_cron`, `qds.snapshots.min_interval_minutes`) with defaults 6h/4h/55min. **Reported as a missing decision; needs an ADR** (cost governance is P4).
5. **Content-type mapping judgment calls** (not canonically specified): IG post-scraper `Video` items map to `REEL`; TikTok/YouTube SHORT vs VIDEO classified by configurable duration threshold (default 60s). Flag if the reviewer considers this an invented fact — the alternative (quarantining every video) loses data.
6. **Provenance `sourceVersion`** = actor id (Apify) / `youtube-data-api-v3`. The run-sync endpoint doesn't return an actor build number; the actor id is the best reproducibility proxy available without a second API call.
7. **Snapshot scheduler reads DB state, not live providers** — per architecture §2.1 ("reads existing accounts/content"); a snapshot's `capturedAt` may therefore trail the underlying `fetchedAt` by up to one poll interval. The provenance carries the true fetch time.

## 5. Tests & checks

- **Executed (passing):**
  - `XDEBUG_MODE=off php artisan test` — **186 passed (582 assertions)**, including 55 new ingestion/snapshot tests.
  - `vendor/bin/phpstan analyse` (level 6, larastan) — clean.
  - `vendor/bin/pint` — clean.
  - `php artisan migrate:fresh` — all migrations incl. the two new ones.
  - `npm run build` — Vite build OK.
- **Executed (failing / skipped):** none.
- **Live smoke test (2026-07-05, real credentials supplied by user):**
  - **VERIFIED working against live data** — field mappings + provenance confirmed:
    - `SRC-apify-instagram-profile-scraper` (natgeo: 269M followers, bio, links).
    - `SRC-apify-instagram-post-scraper` (5 items; likes/comments/views; a `Video` post correctly mapped to `REEL`).
    - `SRC-apify-instagram-reel-scraper` (5 items; plays/views/likes/comments).
    - `SRC-clockworks-tiktok-scraper` profile + content (mrbeast: 129M followers; `SHORT` classification; views/likes/comments/shares/saves).
    - `SRC-youtube-data-api-v3` profile + content (mrbeast: 506M subscribers; three-call chain channel→uploads→videos; `SHORT`/`VIDEO` classification; views/likes/comments) — verified after the user supplied a valid key (a first key was rejected `API_KEY_INVALID`).
    - **End-to-end persistence + idempotency + DB round-trip**: reels AND YouTube content persisted (run1 created=5), re-run created=0/duplicates=5 (idempotent); provenance and metric tier (PUBLIC) preserved through the DB.
  - **NOT verified — action required (NOT code defects):**
    - `SRC-apify-instagram-story-details` (story archival): **blocked on a paid Apify actor**, unresolved after trying two actors. (1) The doc-named `louisdeconinck` actor: `…instagram-story-details` slug 404s; the real `…instagram-stories-scraper` slug exists but returns 401/403 (rental). (2) The operator then chose `datavoyantlab~advanced-instagram-stories-scraper` (now the config default) — it runs but returns an access-error item ("only available for paying users") on the free account. **Both story actors require payment.** Story output field mapping (id/media/expiry) therefore **remains unverified against live payloads**; the synthetic fixture is a best-effort guess and will need re-checking once a paid actor runs. The failure is now clean and diagnosable (see code change 3).
  - **Code changes made during the smoke test (all retested, all green):**
    1. `config/services.php` — story actor slug default now `datavoyantlab~advanced-instagram-stories-scraper` (operator's choice). **Deviation flagged:** the data-source matrix names the `louisdeconinck` actor; this uses `datavoyantlab`. The SRC-* contract id (provenance) is unchanged — only the underlying Apify actor differs — but this needs a doc/ADR note (§4).
    2. `app/Platform/Ingestion/Http/YouTubeClient.php` — an invalid API key (HTTP 400 "API key not valid") now classifies as `AUTHENTICATION` instead of `UNKNOWN`. Regression test added.
    3. `app/Platform/Ingestion/Http/ApifyClient.php` — a paid/rental actor that returns HTTP 201 with a single access/paywall **error item** (no real data) now raises a call-level `AUTHENTICATION` failure (sanitized, no user identifiers) instead of being silently quarantined as a vague "missing id" record — verified live against the datavoyantlab actor. Regression test + fixture added.
- **Not executed:** S3-compatible object storage (local `media` disk used; story media archival could not be exercised live since no story payload was retrievable).
- **Environment notes:** (a) local Xdebug 3.3.2 segfaults (SIGSEGV) when a `ConnectionException` passes through Guzzle's fake handler — run tests with `XDEBUG_MODE=off`; not a code defect. (b) The Claude Code Bash sandbox blocks outbound network egress — the live smoke test required `dangerouslyDisableSandbox`; the initial run's `NETWORK`/`TIMEOUT` cascade was the sandbox, not the providers.

---

## 6. Review checklist

> Reviewer: mark `[x]` only after you have **verified** the item. Add a note per item.

### 6.1 Canonical fidelity
- [ ] Implementation matches the cited canonical docs (statuses, entities, enums, ownership).
- [ ] No canonical fact was silently changed to make the code pass.

### 6.2 Architecture & ownership
- [ ] Respects module boundaries and the ownership matrix.
- [ ] No cross-module reach-through or leaked responsibility.

### 6.3 Security & personal data
- [ ] AuthN/AuthZ correct for every new surface (routes, actions, policies).
- [ ] Personal-data handling matches data principles; no over-collection / leak.

### 6.4 Correctness
- [ ] Core logic is correct on the happy path and documented edge cases.
- [ ] Error/empty/unavailable states behave per spec (e.g. unavailable-never-empty).

### 6.5 Migrations & database
- [ ] Migrations are reversible or the destructive risk is called out and accepted.
- [ ] Schema matches the data model; constraints / indexes / FKs are correct.

### 6.6 Test adequacy
- [ ] Tests cover the new behavior, not just the happy path.
- [ ] Failure modes and boundaries are asserted.

### 6.7 Adversarial verification
- [ ] Each finding below was independently verified (not taken on first read).

---

## 7. Suggested review scenarios — where to focus

1. **Live-credential smoke test (highest value, blocked on secrets)** — *Focus:* all adapters. *Do:* set `APIFY_TOKEN`/`YOUTUBE_API_KEY`, `QDS_INGESTION_ENABLED=true`; run `app(IngestionService::class)->ingestPlatformAccount(...)` for one real roster account per platform. *Expected:* rows in `content_items`/`stories`/`provider_calls` with correct provenance; quarantine only for genuinely bad items. *Risk if wrong:* every fixture-derived field mapping (actor input shapes AND output field names) is unproven — this is the single biggest unknown.
2. **Cycle-slot accounting under failure interleavings** — *Focus:* `IngestionJobBehaviour::completeCycleSlot` + `failed()` + rate-limit `release()`. *Do:* trace a job that is released twice then permanently fails, and a job whose model row is missing. *Expected:* `jobs_pending` reaches exactly 0, never negative; cycle finalizes once; no slot double-decrement (release path must NOT decrement). *Risk if wrong:* cycles stuck RUNNING forever or finalized early (duplicate-cycle guard then blocks future polls until stale timeout).
3. **Human-override preservation vs. metric refresh** — *Focus:* `ContentItemPersister`/`StoryPersister`. *Do:* set `human_overrides=['caption','public_metrics']`, re-ingest. *Expected:* both fields untouched; provenance still refreshed. *Risk if wrong:* DP-004 violation — corrections silently clobbered.
4. **Signed media URL surface** — *Focus:* `StoryMediaController` + `monitoring.stories.media` route (deliberately not auth-gated; the signature is the credential). *Do:* attempt expired signature, tampered story id, path traversal via `media_url`. *Expected:* 403/404; no way to read arbitrary disk paths (`media_url` is only ever written by `ArchiveStoryMediaJob`). *Risk if wrong:* private media exposure (GDPR).
5. **Quarantine/redaction completeness** — *Focus:* `PayloadRedactor` deny-list. *Do:* feed payloads with tokens/emails/signed URLs in unusual keys (`accessToken`, `contact_email`). *Expected:* nothing sensitive persisted in `quarantined_records`/`provider_response_samples`. *Risk if wrong:* secrets/personal data at rest in ops tables.
6. **Snapshot dedup boundary** — *Focus:* `DatabaseSnapshotScheduler::isDue`. *Do:* two runs 54 vs 56 minutes apart; concurrent runs (cache lock). *Expected:* exactly one point per interval per target. *Risk if wrong:* duplicate history points skew DERIVED aggregates later.

## 8. High-risk areas

- `app/Platform/Ingestion/Providers/**` — **fixture-shaped field mappings, zero live verification.** Any mismatch with real actor output silently quarantines everything (visible via SCHEMA_DRIFT alerts, but still no data).
- `app/Platform/Ingestion/Jobs/Concerns/IngestionJobBehaviour.php` — slot bookkeeping correctness across release/retry/fail interleavings; `failed()` firing depends on worker semantics per queue driver.
- `app/Platform/Ingestion/Jobs/IngestContentJob.php` — partial-failure policy: when one provider succeeds and another fails transiently, the failure is recorded but NOT retried this cycle (deliberate; documented in code). Confirm acceptable.
- `app/Modules/Monitoring/routes.php` — the `stream` route relies solely on signature validation (`hasValidSignature`), by design. Confirm the threat model is acceptable for internal use.

---

## 9. Reviewer findings

> Filled by the REVIEW model. One checkbox per finding; check when resolved/dispositioned.

- [ ] —

## 10. Review sign-off

- **Reviewer:** —
- **review_status → REVIEWED on:** —
- **outcome:** —
- **Summary:** —
