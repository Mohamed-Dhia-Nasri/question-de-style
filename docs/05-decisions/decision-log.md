---
id: decision-log
title: Decision Log (ADR Ledger)
status: APPROVED
canonical_for:
  - ADR-*
depends_on:
  - docs/00-meta/03-glossary.md
  - docs/30-data-model/00-data-model.md
  - docs/20-cross-cutting/01-deferred-register.md
  - docs/40-integrations/00-data-source-matrix.md
last_reviewed: 2026-07-04
---

# Decision Log (ADR Ledger)

This file is the **sole home** of every Architecture Decision Record (`ADR-*`) for Question de Style (QDS). No other file defines, restates, or re-decides an ADR; other files reference an ADR by its ID and link here. If a decision changes, it is amended **here** (with a new status such as `SUPERSEDED`) and a superseding ADR is added to the ledger.

Each ADR below uses the fixed four-part body format: **Context / Decision / Status / Consequences**. The `Status` line carries one [`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus) value. All thirteen ADRs (`ADR-0001` .. `ADR-0013`) are `APPROVED`.

Related canonical files (do not restate their facts here — link):

- Provider stack and per-source contracts: [40-integrations/00-data-source-matrix.md](../40-integrations/00-data-source-matrix.md)
- Deferred items (`DEF-*`) and the unavailable-never-empty UI rule: [20-cross-cutting/01-deferred-register.md](../20-cross-cutting/01-deferred-register.md)
- Cross-cutting data principles (`DP-*`): [20-cross-cutting/00-data-principles.md](../20-cross-cutting/00-data-principles.md)
- Enums (including [`ENUM-MetricTier`](../00-meta/03-glossary.md#enum-metrictier)): [00-meta/03-glossary.md](../00-meta/03-glossary.md)
- Shared envelopes (Provenance, ConfidenceAssessment): [30-data-model/00-data-model.md](../30-data-model/00-data-model.md)

---

## Ledger

| ADR | Title | Status | What it settles |
|-----|-------|--------|-----------------|
| [ADR-0001](#adr-0001) | v1 technology stack frozen | APPROVED | The closed v1 provider stack (Apify public IG + TikTok, YouTube Data API v3, Google Vision/Speech-to-Text/Video Intelligence, QDS own DB + AI); no other providers may be invented. |
| [ADR-0002](#adr-0002) | TikTok via Apify only | APPROVED | TikTok data comes only from the Apify Clockworks actor; no usable official TikTok API exists for commercial monitoring/discovery. |
| [ADR-0003](#adr-0003) | Historical growth via own-DB snapshots | APPROVED | Historical performance is produced by recurring timestamped MetricSnapshot records, not any external history API. |
| [ADR-0004](#adr-0004) | Audience demographics deferred | APPROVED | Audience country/age/gender is out of v1 scope (maps to DEF-001); needs a specialist provider. |
| [ADR-0005](#adr-0005) | Manual contact entry for v1 | APPROVED | Creator contact (email/phone) is entered manually in the CRM for v1; auto-extraction is deferred (maps to DEF-002). |
| [ADR-0006](#adr-0006) | Metric tiering and deferred true reach | APPROVED | Every metric is tiered PUBLIC/DERIVED/ESTIMATED/CONFIRMED; true unique reach/impressions is deferred (maps to DEF-003). |
| [ADR-0007](#adr-0007) | OAuth authorized-creator analytics deferred | APPROVED | OAuth authorized-creator analytics flows (Meta/TikTok/YouTube Insights) are out of v1 scope (maps to DEF-004). |
| [ADR-0008](#adr-0008) | Confidence-first and provenance-first data doctrine | APPROVED | Every inferred/estimated value carries a ConfidenceAssessment; every externally-sourced record carries Provenance. |
| [ADR-0009](#adr-0009) | Comment analysis deferred for v1 (cost) | APPROVED | Bulk comment collection & audience-reaction analysis (REQ-M1-010) is out of v1 scope on cost grounds (maps to DEF-005). |
| [ADR-0010](#adr-0010) | Analytics DB & dimensional model | APPROVED | Aggregation-heavy reporting is served by a star schema on Neon Postgres (facts/dimensions/rollups; scheduled materialized-view rollups), append-only and tier-aware; ClickHouse escape hatch. Amended by ADR-0013 (Neon, not TimescaleDB). |
| [ADR-0011](#adr-0011) | Roster-first monitoring | APPROVED | Module 1 monitoring focuses on the agency's tracked-creator roster (activity + seeded-product content → EMV); open-web brand/keyword listening from non-roster creators is deferred (DEF-006). |
| [ADR-0012](#adr-0012) | Application stack: Laravel + TailAdmin, no Filament | APPROVED | v1 application is Laravel 12 with the TailAdmin (Blade / Alpine.js / Tailwind v4) template for all UI; CRUD and dashboards are hand-built with Livewire + Alpine (+ spatie/laravel-permission), not Filament. Resolves the Laravel-vs-TypeScript branch as Laravel. |
| [ADR-0013](#adr-0013) | Deployment: Hetzner + Docker; DB on Neon | APPROVED | App containerized with Docker on Hetzner (EU); database on Neon serverless Postgres (EU). Amends ADR-0010: engine is Neon Postgres (native partitioning + scheduled rollups), not TimescaleDB. Kubernetes deferred. |

---

<a id="adr-0001"></a>
## ADR-0001 — v1 technology stack frozen

**Context.**
QDS ingests public social data across three platforms ([`ENUM-Platform`](../00-meta/03-glossary.md#enum-platform): INSTAGRAM, TIKTOK, YOUTUBE) and enriches it with AI recognition, sentiment, and classification. Without a fixed provider set, AI coding agents and contributors would invent inconsistent or non-existent data providers, causing integration drift and hallucinated capabilities.

**Decision.**
The v1 technology/provider stack is **frozen and closed**. The only permitted external providers are the Apify actors for public Instagram data, the Apify Clockworks actor for public TikTok data, the official YouTube Data API v3, and Google Vision / Speech-to-Text / Video Intelligence for AI recognition. Everything else (primary datastore, enrichment/AI orchestration, application logic) is QDS's own database and AI. The authoritative, contract-level provider registry (`SRC-*`) lives in [40-integrations/00-data-source-matrix.md](../40-integrations/00-data-source-matrix.md); this ADR only fixes the *policy* that the stack is closed. No agent may add, swap, or invent a provider without a superseding ADR.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Cross-cutting principle [`DP-006`](../20-cross-cutting/00-data-principles.md) (stack lock) enforces this decision and cites `ADR-0001` as its source of authority.
- New capabilities that would require a new provider must be captured as a deferred item in [20-cross-cutting/01-deferred-register.md](../20-cross-cutting/01-deferred-register.md) and gated behind a superseding ADR.
- Downstream decisions `ADR-0002` (TikTok source), `ADR-0004`, `ADR-0006`, and `ADR-0007` all narrow specific gaps created by keeping the stack closed.

---

<a id="adr-0002"></a>
## ADR-0002 — TikTok via Apify only

**Context.**
TikTok exposes no official API usable for commercial third-party monitoring or discovery. The Research API and Commercial Content API are restricted to approved researchers, and the Display API is authorized-creator-only. QDS needs public TikTok content, engagement, keyword search (videos + profiles), profiles, and comments for Modules 1 and 2 without creator authorization.

**Decision.**
All TikTok data is sourced **exclusively** through the Apify Clockworks TikTok actor (registered as a `SRC-*` in [40-integrations/00-data-source-matrix.md](../40-integrations/00-data-source-matrix.md)). It is the single TikTok source for views/likes/comments/shares/saves, keyword search, profiles, and comments. No official TikTok API is used in v1.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Refines `ADR-0001` for the TikTok platform specifically.
- Scraper fragility (TikTok anti-bot) becomes a first-class risk, tracked in the roadmap ([80-delivery/00-roadmap.md](../80-delivery/00-roadmap.md)) and addressed by data-quality/health monitoring in the P4 hardening phase.
- If TikTok releases a usable commercial API later, a superseding ADR is required to change the source; the matrix is not edited unilaterally.

---

<a id="adr-0003"></a>
## ADR-0003 — Historical growth via own-DB snapshots

**Context.**
The permitted sources return point-in-time public metrics only; none of them provides a historical time series of follower/engagement growth. QDS still needs historical performance tracking (requirement `REQ-M1-007`).

**Decision.**
Historical growth and performance history are produced **inside QDS** by recurring, timestamped `MetricSnapshot` records written by the snapshot scheduler service. There is no external history API. The snapshot entity shape is defined canonically in [30-data-model/00-data-model.md](../30-data-model/00-data-model.md); this ADR only fixes that history is snapshot-derived.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- History begins accumulating only from first snapshot onward; no backfill of pre-QDS history is possible.
- The snapshot scheduler service is foundational and is stood up in the P0 phase ([80-delivery/00-roadmap.md](../80-delivery/00-roadmap.md)) so that history is already accruing before Modules 2 and 3 consume it.
- Snapshot cadence and cost are governed under the P4 cost/rate-limit hardening work.

---

<a id="adr-0004"></a>
## ADR-0004 — Audience demographics deferred

**Context.**
Audience demographics (audience country, age, gender) require a specialist audience-intelligence provider (e.g. Modash / HypeAuditor). No such provider is in the frozen v1 stack (`ADR-0001`), and public scraping cannot reliably produce demographic breakdowns.

**Decision.**
Audience demographics are **deferred out of v1 scope**. This maps to the deferred item `DEF-001` (canonical in [20-cross-cutting/01-deferred-register.md](../20-cross-cutting/01-deferred-register.md)).

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Advanced discovery filters (`REQ-M2-002`) exclude audience-country/age/gender in v1; these fields render **"unavailable"** (never empty or zero) per the deferred-register UI rule.
- Adding demographics later requires a new provider and therefore a superseding ADR against `ADR-0001`.

---

<a id="adr-0005"></a>
## ADR-0005 — Manual contact entry for v1

**Context.**
Creator contact details (email, phone) are not reliably returned by the permitted sources — notably the Instagram profile scraper does not return email or phone. Auto-extraction of contacts also raises GDPR/ToS exposure on EU-creator personal data.

**Decision.**
For v1, creator contact and address data is **entered manually** in the CRM (requirement `REQ-M3-002`). Automatic contact extraction is **deferred** and maps to `DEF-002` (canonical in [20-cross-cutting/01-deferred-register.md](../20-cross-cutting/01-deferred-register.md)).

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- No pipeline attempts to scrape or infer contact fields in v1.
- Contact fields with no manual entry render **"unavailable"**, never empty or zero.
- Manual entry keeps EU-creator personal data handling auditable, supporting the GDPR/ToS constraint in [`DP-005`](../20-cross-cutting/00-data-principles.md).

---

<a id="adr-0006"></a>
## ADR-0006 — Metric tiering and deferred true reach

**Context.**
The sources yield public counts (views, likes, plays) but not authorized-analytics figures such as true unique reach and impressions. Presenting modeled or public numbers as if they were confirmed analytics would be misleading and would violate the confidence-first doctrine.

**Decision.**
Every metric is tagged with a tier from [`ENUM-MetricTier`](../00-meta/03-glossary.md#enum-metrictier): `PUBLIC` (directly observed, e.g. views/likes), `DERIVED` (deterministically computed from PUBLIC values — engagement rate, average performance, and median performance are **DERIVED, never PUBLIC**), `ESTIMATED` (modeled/inferred, e.g. estimated reach), and `CONFIRMED` (from authorized analytics or manual agency input). True unique reach and impressions (`CONFIRMED` reach) are **deferred**, mapping to `DEF-003`; v1 shows `PUBLIC` views/plays plus a clearly-labelled `ESTIMATED` reach only. Enum values are canonical in the glossary and are not restated here beyond this decision.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Enforced by [`DP-001`](../20-cross-cutting/00-data-principles.md) (metric tiering): an `ESTIMATED` value is never presented as fact.
- Governs reach/impressions tiering (`REQ-M1-006`) and discovery performance analysis (`REQ-M2-006`, which surfaces both average and median as `DERIVED`).
- `DEF-003` (canonical in [20-cross-cutting/01-deferred-register.md](../20-cross-cutting/01-deferred-register.md)) tracks the deferred true-reach capability; unlocking it requires authorized analytics, coupled to `ADR-0007`.

---

<a id="adr-0007"></a>
## ADR-0007 — OAuth authorized-creator analytics deferred

**Context.**
Confirmed, first-party analytics (Meta / TikTok / YouTube Insights) require OAuth-authorized creator consent flows. Building and operating these consent integrations is out of scope for v1 and depends on creator opt-in that the agency cannot guarantee at launch.

**Decision.**
OAuth authorized-creator analytics flows are **deferred out of v1 scope**. This maps to `DEF-004` (canonical in [20-cross-cutting/01-deferred-register.md](../20-cross-cutting/01-deferred-register.md)).

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- The only path to `CONFIRMED`-tier reach/analytics (per `ADR-0006`) is deferred with this decision, so v1 relies on `PUBLIC` and `ESTIMATED` tiers.
- Manual agency input remains the sole `CONFIRMED` source in v1.
- Enabling OAuth analytics later requires a superseding ADR and expands the frozen stack under `ADR-0001`.

---

<a id="adr-0008"></a>
## ADR-0008 — Confidence-first and provenance-first data doctrine

**Context.**
QDS mixes directly observed public data with AI-inferred and modeled values (location, authenticity, organic-vs-paid classification, sector). Treating any inferred value as an unqualified fact, or storing external data without recording where it came from, would make the platform untrustworthy and un-auditable and would break GDPR/ToS accountability.

**Decision.**
Adopt a **confidence-first and provenance-first** data doctrine:

- **Provenance-first.** Every externally-sourced record carries the `Provenance` envelope — `{ source (SRC-* id), fetchedAt, sourceVersion }` — which is mandatory. The envelope shape is defined canonically in [30-data-model/00-data-model.md](../30-data-model/00-data-model.md).
- **Confidence-first.** Every inferred or estimated value carries a `ConfidenceAssessment` envelope — `{ value, confidenceLevel ([ENUM-ConfidenceLevel](../00-meta/03-glossary.md#enum-confidencelevel)), signals, verificationStatus ([ENUM-VerificationStatus](../00-meta/03-glossary.md#enum-verificationstatus)) }`. This applies to location, authenticity, organic-vs-paid classification, and sector. The valid AI verification value is `AI_ASSESSED`.
- Consistent with [`ENUM-MentionType`](../00-meta/03-glossary.md#enum-mentiontype): a mention is only `PAID` or `SEEDED` when a record/label proves it; otherwise it is `LIKELY_ORGANIC` or `UNKNOWN`. Organic is never asserted as fact (there is no `CONFIRMED_ORGANIC` value).

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- This ADR is registered in the ledger above and is the source of authority for the data principles and roadmap: [`DP-002`](../20-cross-cutting/00-data-principles.md) (provenance mandatory), [`DP-003`](../20-cross-cutting/00-data-principles.md) (confidence-based assessments), and [`DP-004`](../20-cross-cutting/00-data-principles.md) (human-in-the-loop) implement it, and the roadmap ([80-delivery/00-roadmap.md](../80-delivery/00-roadmap.md)) references `ADR-0008` in its P0 provenance/confidence-enforcement work via `depends_on`.
- Low-confidence AI outputs route to a human review queue; corrections are stored and feed back into future rules ([`DP-004`](../20-cross-cutting/00-data-principles.md)), moving `verificationStatus` from `AI_ASSESSED` toward `HUMAN_REVIEWED` / `HUMAN_CORRECTED` / `CONFIRMED`.
- Reinforces `ADR-0006`: estimated values (e.g. reach) are surfaced with both a metric tier and a confidence assessment, never as bare facts.

---

<a id="adr-0009"></a>
## ADR-0009 — Comment collection and audience-reaction analysis deferred for v1

**Context.**

Comment collection is not required for QDS's core value — brand-mention monitoring, paid/seeded/organic classification, performance metrics, EMV, discovery, and CRM/seeding all function without it. It is, however, the single largest driver of ingestion volume and cost: comments are billed per result by the Apify sources and represent roughly half of all scraped results at typical monitoring scale. Storing third-party commenter personal data at scale also broadens GDPR/ToS exposure ([`DP-005`](../20-cross-cutting/00-data-principles.md)).

**Decision.**

Defer [REQ-M1-010](../90-traceability/00-req-matrix.md) (comment collection and audience-reaction analysis) from v1. No `Comment` records are collected and no comment-analysis feature ships in v1. The deferral is tracked as [DEF-005](../20-cross-cutting/01-deferred-register.md#def-005). Sentiment ([REQ-M1-009](../90-traceability/00-req-matrix.md)) runs on captions/transcripts only, and authenticity ([REQ-M2-007](../90-traceability/00-req-matrix.md)) uses non-comment public signals only.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Removes roughly 40k of ~82k monthly scraped results at the reference 500-creator scale, lowering the Apify line and the overall operating profile by about 20%.
- The `Comment` entity remains defined in the [data model](../30-data-model/00-data-model.md#ent-comment) but is unpopulated in v1; consuming surfaces must render comment-derived data as **"unavailable"** per [DEF-005](../20-cross-cutting/01-deferred-register.md#def-005).
- Re-activation is expected as **selective** collection (campaign/seeding/flagged content only), not blanket collection, to preserve the cost profile.

---

<a id="adr-0010"></a>
## ADR-0010 — Analytics database and dimensional model strategy

> **Amended ([ADR-0013](#adr-0013)):** the engine is **Neon Postgres**, not TimescaleDB (Neon does not support the Timescale extension or `pg_cron`). The star schema is unchanged; rollups are scheduled materialized views instead of continuous aggregates.

**Context.**

QDS is aggregation-heavy: every metric entered or measured is tracked over time and reported across many dimensions and grains — per week/month/quarter/year, per brand, per product, per content type, per city, per country, per campaign. A concrete driver is seeding analytics: tracking a shipment per influencer (shipped, posted, when, reach/views/likes) and aggregating a product seeded to many influencers into one total (reach/views/estimated impressions/EMV). Computing these live over normalized OLTP tables does not scale.

**Decision.**

Keep **PostgreSQL** as the operational (OLTP) system of record for all `ENT-*`. Serve analytics (OLAP) from a **dimensional star schema** — fact tables (`FACT-*`), dimensions (`DIM-*`), and pre-aggregated rollups (`ROLLUP-*`) — canonical in [`../30-data-model/01-analytics-model.md`](../30-data-model/01-analytics-model.md). Implement it on **Neon Postgres** (see [ADR-0013](#adr-0013)): fact tables use **native declarative partitioning** by time, and rollups are **materialized views / rollup tables refreshed on a schedule** by `SVC-Analytics` (Neon supports neither the TimescaleDB extension nor `pg_cron`, so the refresh is driven by the app scheduler). This keeps analytics in the same database as OLTP for v1 — no second system. Facts are **append-only** and **tier-aware** (every measure carries its [`ENUM-MetricTier`](../00-meta/03-glossary.md#enum-metrictier); estimates never aggregate into a number that reads as fact — [DP-001](../20-cross-cutting/00-data-principles.md)). The star schema is engine-portable: at very large scale, facts stream to a columnar engine (**ClickHouse**) or warehouse via CDC — adopted only when needed.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- One database in v1 (Neon Postgres); dashboards and [`SVC-Export`](../60-architecture/00-system-architecture.md) read rollups only, never raw facts.
- Introduces the `FACT-*`, `DIM-*`, `ROLLUP-*` ID families (grammar in [`../00-meta/01-conventions.md`](../00-meta/01-conventions.md)); a new maintenance service `SVC-Analytics` owns facts + rollups.
- This decision is **independent of the application framework** — the star schema works on any backend (Laravel, TypeScript, or Python).
- The roadmap adds an analytics-foundation step (schema + partitioning + scheduled rollup catalog) before the reporting-heavy features that depend on it.

---

<a id="adr-0011"></a>
## ADR-0011 — Roster-first monitoring

**Context.**

Question de Style's core monitoring need is to follow **its own creators** — the influencers already working with the agency — not to listen to the entire open web. The agency seeds products to these creators and needs to: track each creator's overall activity (reach, follower count, likes, comments, last post); detect the reel/post/story where a creator shows a seeded product; capture that content's metrics; and compute EMV and campaign statistics from it. Broad open-web brand/keyword/hashtag listening (finding mentions from unknown creators) is expensive — its scraping cost scales with the whole platform, not a known list — and is not the primary need.

**Decision.**

Module 1 monitoring is **roster-first**. The monitoring roster is modelled as [`ENT-MonitoredSubject`](../30-data-model/00-data-model.md#ent-monitoredsubject)s of type `CREATOR` ([`ENUM-MonitoredSubjectType`](../00-meta/03-glossary.md#enum-monitoredsubjecttype)), each referencing a tracked [`ENT-Creator`](../30-data-model/00-data-model.md#ent-creator). Monitoring polls each tracked creator's [`ENT-PlatformAccount`](../30-data-model/00-data-model.md#ent-platformaccount)s for profile metrics and new content, and detects seeded-product content among that content (matched to Module 3 shipments per [REQ-M3-008](../90-traceability/00-req-matrix.md)) → captures metrics → EMV. **Open-web listening** for mentions from non-roster creators (the `BRAND`/`KEYWORD`/`HASHTAG`/`HANDLE` subject types) is deferred as [DEF-006](../20-cross-cutting/01-deferred-register.md#def-006).

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Monitoring scope, sources, and cost are bounded by the roster size, not the open platform — materially cheaper and simpler than broad social listening.
- `REQ-M1-001` is roster monitoring; the open-web term modes are deferred (`DEF-006`) and their UI renders "unavailable".
- Tightens the Module 1 ↔ Module 3 link: the seeded-product content Module 1 detects on roster creators feeds seeding results and product-level aggregation ([REQ-M3-009](../90-traceability/00-req-matrix.md), [REQ-M3-013](../90-traceability/00-req-matrix.md)).

---

<a id="adr-0012"></a>
## ADR-0012 — Application stack: Laravel + TailAdmin (Blade/Livewire), no Filament

**Context.**
The data layer is fixed by `ADR-0010` (Neon Postgres — see [ADR-0013](#adr-0013)) and is deliberately **independent of the application framework**. Two questions remained open above it: the application framework (Laravel vs a TypeScript Next.js/NestJS stack) and the admin/dashboard UI approach (a CRUD-generation framework such as Filament vs a hand-built UI on a design template). The product is roughly 40% CRM/campaign CRUD (Module 3 — contacts, campaigns, seeding, shipments, roles, documents, tasks), 40% analytics dashboards, and 20% ingestion orchestration. The **TailAdmin Laravel** admin template (MIT-licensed) was selected as the frontend, which fixes the framework as Laravel and the UI foundation as Blade/Alpine/Tailwind.

**Decision.**
The v1 application stack is:

- **Framework.** Laravel 12 (PHP 8.2+, Composer, Node 18+, Vite). This **resolves the Laravel-vs-TypeScript branch as Laravel**; the TypeScript Next.js/NestJS alternative is closed for v1.
- **UI foundation.** The **TailAdmin Laravel** template — Blade templating, Alpine.js, Tailwind CSS v4 — used for both the internal admin/CRM surfaces and the analytics dashboards.
- **Interactivity & CRUD.** Built **by hand with Livewire** components inside TailAdmin's Blade shell. **Filament is not used.** All data tables (sorting, filtering, pagination), form validation and model binding, authorization-aware navigation, and dashboard widgets are implemented directly.
- **Roles/permissions.** spatie/laravel-permission (Filament's built-in authorization is not present).
- **Charts/maps.** The libraries the template bundles (ApexCharts for charts; MapLibre GL / Leaflet / AmCharts 5 for maps), consuming the analytics rollups defined in `ADR-0010`.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- **Effort is the main cost.** Dropping Filament means the CRUD-heavy Module 3 is hand-built: the tables, forms, validation, and role checks a CRUD framework would have generated must be budgeted as explicit implementation work in the roadmap ([80-delivery/00-roadmap.md](../80-delivery/00-roadmap.md)).
- **One design system.** Admin and dashboards share the single TailAdmin visual language; there is no second UI framework to theme or maintain.
- **Data-layer alignment unchanged.** Dashboards and [`SVC-Export`](../60-architecture/00-system-architecture.md) read rollups only, never raw facts (`ADR-0010`); metric-tier awareness and the "unavailable, never empty" rule for deferred fields still govern every surface.
- **Licensing.** TailAdmin is MIT-licensed, so it can be adapted and shipped in the commercial product without a redistribution constraint.
- Changing the framework or reintroducing a CRUD-generation framework later requires a superseding ADR.

---

<a id="adr-0013"></a>
## ADR-0013 — Deployment, hosting, and database platform

**Context.**

The application stack is fixed by [ADR-0012](#adr-0012) (Laravel 12 + TailAdmin) and the analytics model by [ADR-0010](#adr-0010). The hosting target, container strategy, and managed-database platform remained open. As a DACH agency handling EU-creator personal data, EU data residency is required ([DP-005](../20-cross-cutting/00-data-principles.md)).

**Decision.**

- **Database — Neon.** Both OLTP and OLAP run on **Neon** (serverless Postgres), **EU region (Frankfurt)**. This **amends [ADR-0010](#adr-0010)**: the engine is Neon Postgres, **not** TimescaleDB — Neon supports neither the TimescaleDB extension nor `pg_cron`. The star schema is unchanged; fact tables use **native declarative partitioning** and rollups are **materialized views / rollup tables refreshed on a schedule** by `SVC-Analytics` via the app scheduler. Scale-to-zero is **disabled** on the production branch; Neon **branching** is used for dev/preview databases.
- **Deployment — Hetzner + Docker.** The application tier (Laravel/PHP-FPM, nginx, Redis, Horizon worker, scheduler) is **containerized with Docker**. **Docker Compose** is used for local development (dev↔prod parity) and for **single-host production on Hetzner** (Germany/EU). The database is **not** containerized — it is Neon.
- **Object storage.** Hetzner Object Storage (or Cloudflare R2) for media (expiring stories) and documents.
- **Kubernetes is deferred** — one Hetzner host with Compose suffices for v1; revisit only if a single host is outgrown.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- **Data residency.** Neon EU + Hetzner Germany satisfy the GDPR/DACH constraint ([DP-005](../20-cross-cutting/00-data-principles.md)).
- **No TimescaleDB.** Rollups are refreshed on a schedule (e.g. every 15–60 min) by `SVC-Analytics` rather than maintained automatically; native partition detach/drop replaces Timescale compression. See the [analytics model](../30-data-model/01-analytics-model.md).
- **Backups + scaling.** Neon manages backups and autoscales compute for heavy aggregation queries; the app tier is stateless and horizontally scalable behind nginx.
- **Escape hatch unchanged.** At very large fact volume, move the OLAP layer to Timescale Cloud or **ClickHouse** ([ADR-0010](#adr-0010)) — the star schema is engine-portable.