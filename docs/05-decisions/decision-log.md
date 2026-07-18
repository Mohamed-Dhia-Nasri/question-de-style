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
last_reviewed: 2026-07-17
---

# Decision Log (ADR Ledger)

This file is the **sole home** of every Architecture Decision Record (`ADR-*`) for Question de Style (QDS). No other file defines, restates, or re-decides an ADR; other files reference an ADR by its ID and link here. If a decision changes, it is amended **here** (with a new status such as `SUPERSEDED`) and a superseding ADR is added to the ledger.

Each ADR below uses the fixed four-part body format: **Context / Decision / Status / Consequences**. The `Status` line carries one [`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus) value. All twenty-six ADRs (`ADR-0001` .. `ADR-0026`) are `APPROVED`.

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
| [ADR-0014](#adr-0014) | Operator-managed creator identity; merge deferred | APPROVED | v1 creator identity is curated by operators by hand (create creators, add/remove platform accounts); the *automatic* same-person detection (AC-M3-003) and the *dedicated auditable/reversible merge* (AC-M3-001) of REQ-M3-001 are deferred out of v1. The central influencer DB + cross-platform identity are delivered. |
| [ADR-0015](#adr-0015) | Internal manual-entry provenance source | APPROVED | Registers `SRC-agency-manual-entry` as an internal (non-provider) `Provenance` source for records agency staff enter by hand — required for operator-added platform accounts under ADR-0014. The external provider stack stays frozen (ADR-0001). |
| [ADR-0016](#adr-0016) | No external client access in v1 — CLIENT_VIEWER surface dropped | APPROVED | The agency has no external clients, so v1 ships no client login, approved-report surface, or report-approval workflow; `CLIENT_VIEWER` stays a defined, deny-everything `ENUM-RoleName` value and the P4 white-label client-report deliverable is void unless this ADR is superseded. |
| [ADR-0017](#adr-0017) | Provider-cost governance: refresh-window polling, batched story runs, and cost-side circuit breaking | APPROVED | Ingestion polling cost posture: provider-side refresh-window date filters + periodic full-depth sweep, stories out of full cycles with batched runs + a kill switch, TikTok profile derived from content payloads, adaptive cadence with campaign exemption, permanent-failure circuit breaker with replay-guarded retries, and campaign-linked direct-URL metric refresh (activates `SRC-apify-instagram-scraper`); ingestion cadence is thereby decided. |
| [ADR-0018](#adr-0018) | Operator-assigned creator geography ahead of Module 2 | APPROVED | Operators may assign a creator's country/region/city from the CRM ahead of P2: the entry is the HUMAN half of REQ-M2-003 (ConfidenceAssessment at HUMAN_REVIEWED, `operator-entry` signal), written only through the M2-owned `CreatorGeography` seam; it feeds `DIM-Geo` so country slices light up; M2's automatic signal-based inference (AC-M2-003a) stays deferred and must never overwrite an operator assignment. |
| [ADR-0019](#adr-0019) | Multi-tenant SaaS foundation: ENT-Tenant, tenant ownership, centralized tenant context | APPROVED | The platform becomes a subscription SaaS: a canonical `ENT-Tenant` owns every business record (NOT NULL `tenantId` + composite tenant FKs), one user belongs to exactly one tenant, the owner is a tenant attribute (not a role), tenant context is a centralized scoped binding (HTTP middleware + queue payload propagation + explicit per-unit-of-work assumption in platform pipelines), natural keys become tenant-scoped, and analytics facts/entity dims carry `tenantId`. Hard authorization enforcement, billing/seats, and invitations are explicitly deferred to follow-up phases. |
| [ADR-0020](#adr-0020) | Hard tenant isolation: fail-closed authorization backstop, scoped analytics reads, tenant-aware validation | APPROVED | Closes the read-path/authorization deferrals of ADR-0019: a `Gate::before` backstop denies any ability against a foreign-tenant model ahead of every permission-only policy; `RollupReader` and every raw analytics read filter by the active tenant; a `TenantRule::exists` factory scopes foreign-key validators; the story-media stream route is `auth`-gated + policy-checked; per-tenant data-quality alerts vs global provider alerts are separated; audit ownership stamps are non-mass-assignable; scheduled/queued tenant work runs under explicit `runAs`. Proven with an adversarial cross-tenant test suite. |
| [ADR-0021](#adr-0021) | Stripe SaaS billing: tenant-as-customer, plan catalog, seat limits, invitations | APPROVED | Closes the billing/invitation deferrals of ADR-0019/0020: each tenant is one Stripe customer (`tenants.stripeCustomerId`, the only trusted webhook→tenant mapping); a config-synced global `ENT-SubscriptionPlan` catalog with per-plan seat allowances; `ENT-TenantSubscription` mirrors Stripe's canonical lifecycle states, written only by the verified-webhook synchronizer; seats = active members (incl. owner), enforced server-side under a per-tenant row lock; secure hashed-token `ENT-TeamInvitation` acceptance; owner-only billing via a `billing.manage` owner-attribute gate; product access gated by the `subscribed` middleware behind `QDS_BILLING_ENFORCED`. Stripe is integrated as a thin hand-rolled HTTP client (Cashier rejected), with Checkout/Billing-Portal hosting all card interactions. |
| [ADR-0022](#adr-0022) | Estimated-reach method: per-tenant configurable linear model | APPROVED | Documents the long-missing MET-EstimatedReach method (REQ-M1-006): `estimated_reach = round(view_weight × views + follower_weight × followers)` per ContentItem, computed in enrichment and stored append-only as `ENT-ReachResult`, from an operator-configured, versioned, tenant-owned `ENT-ReachConfiguration` (Settings → Reach, `reach.manage`, DRAFT→ACTIVE, one active per tenant). Always tier ESTIMATED with a disclosed method; never a raw view count (the follower signal must contribute). The `ReachEstimator` DI binding moves off `UnavailableReachEstimator`. CONFIRMED unique reach stays deferred (DEF-003); its UI tiles are removed rather than shown as a perpetual placeholder. |
| [ADR-0023](#adr-0023) | Enrichment triggers per data pull; sweep demoted to backstop | APPROVED | The AI-enrichment pass for a new ContentItem is dispatched by ingestion at persist time (created rows only, inside the eligibility window, behind the kill switch); a story's pass is dispatched after its media archive lands. The recurring sweep stays scheduled as the recovery backstop for crashed/reaped runs. Closes the flagged "enrichment sweep cadence" gap left open by ADR-0017. |
| [ADR-0024](#adr-0024) | Engagement-trend formula; posting frequency stays undecided | APPROVED | MET-EngagementTrend is canonical: mean observed likes+comments per post over the last N days vs the N days before, as a whole signed percent, tier DERIVED; NULL (unavailable) without both windows or with a zero base. N is per-tenant (default 30, ADR-0025). Rolling windows cannot be served by calendar-grain rollups, so the creator page computes this live over ContentItem.public_metrics — a recorded exception to ADR-0010. Posting frequency remains explicitly undecided and hidden. |
| [ADR-0025](#adr-0025) | Per-tenant monitoring settings & retention policy | APPROVED | New append-only `monitoring_settings` table (latest row per tenant wins) + Settings → Monitoring page (`monitoring-settings.manage`, ADMIN): gift-link/shipment attribution window (default 60d), engagement-trend window (default 30d), story-media keep-time (default 180d, 0 = keep forever), communication-log keep-time (default 0 = keep forever). Reads go through a context-safe resolver (config fallback; tenant-less reads NEVER see another tenant's row); retention prune commands iterate tenants with explicit ownership predicates. Closes the flagged shipment-window gap and the two P4-review retention ADR candidates. |
| [ADR-0026](#adr-0026) | Operational confirmations: confidence cut-points, PAID, sentiment | APPROVED | Product-owner confirmations (2026-07-17): the enrichment confidence cut-points 0.85/0.60 are canonical (env-tunable); `PAID` stays in ENUM-MentionType as an inert compatibility value — only ever asserted from a platform paid-partnership label, never inferred (resolves roadmap post-P1 TODO #1); sentiment (REQ-M1-009) deliberately remains Unavailable — no NLP model is chosen; choosing one is a future ADR. |

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

---

<a id="adr-0014"></a>
## ADR-0014 — Operator-managed creator identity; merge deferred

**Context.**

[REQ-M3-001](../90-traceability/00-req-matrix.md) is the central influencer database **plus** cross-platform identity merge, and its acceptance criteria in the [Module 3 spec](../50-modules/module-3-crm-seeding.md) require more than the database: **AC-M3-001** an operator-confirmed, auditable, **reversible** merge of platform accounts under one [`ENT-Creator`](../30-data-model/00-data-model.md#ent-creator), and **AC-M3-003** that an *automatic* merge below a confidence threshold route to a human review queue instead of auto-committing. Two facts make that automatic and dedicated-merge machinery a poor fit for v1:

- **No authorized identity signal exists in the frozen stack.** The permitted sources ([ADR-0001](#adr-0001)) return only public profile text; a platform-authoritative link between two accounts would require authorized-creator analytics, which is itself deferred ([ADR-0007](#adr-0007), maps to `DEF-004`). Any automatic "same person" verdict is therefore a heuristic over public strings, and AC-M3-003's confidence threshold and signal set are **unspecified** anywhere in the documentation.
- **The operator already holds the authoritative identity.** In practice an agency operator adds a creator and enters that creator's platform accounts and links directly — identity is known human input, not an inference to be scored and reviewed. A wrong automatic merge, by contrast, fuses two people's entire CRM and personal data and is costly to reverse under the GDPR/ToS constraint ([DP-005](../20-cross-cutting/00-data-principles.md)).

**Decision.**

For v1, creator identity is **fully operator-managed**. The operator creates each [`ENT-Creator`](../30-data-model/00-data-model.md#ent-creator) and curates its [`ENT-PlatformAccount`](../30-data-model/00-data-model.md#ent-platformaccount)s by hand; the human is the sole identity authority. All `Creator`/`PlatformAccount` writes still route through `SVC-CRM` per the [ownership matrix](../70-shared/00-ownership-matrix.md) (unchanged). Consequently, deferred out of v1:

- the **automatic** same-person detection and review queue of **AC-M3-003** — no matching signals, no confidence threshold, no candidate queue; and
- the **dedicated auditable/reversible merge and un-merge** operation of **AC-M3-001**.

The **central influencer database and cross-platform identity** portion of [REQ-M3-001](../90-traceability/00-req-matrix.md) is delivered: one `Creator` aggregates its per-platform accounts, curated by operators. This is the identity analogue of the manual-entry stance already taken for creator contacts ([ADR-0005](#adr-0005)).

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Duplicate creators — for example an [XMC-001](../50-modules/module-3-crm-seeding.md) proposal from Module 1/2 that duplicates a manually-added creator — are reconciled by an operator **deleting the stray record**; there is no merge tool and no candidate/merge-manifest machinery.
- **No new `DEF-*` / "unavailable" surface.** Unlike deferrals that hide unavailable *data* ([ADR-0004](#adr-0004), [ADR-0005](#adr-0005)), creator identity is fully available — just human-curated. This deferral removes an absent *feature* (there is simply no merge control in the UI), not a data field.
- **Known limitation (recorded, not fixed).** Because a `PlatformAccount` anchors Module 1's monitoring data ([`ENT-ContentItem`](../30-data-model/00-data-model.md#ent-contentitem), [`ENT-Story`](../30-data-model/00-data-model.md#ent-story), and [`ENT-MetricSnapshot`](../30-data-model/00-data-model.md#ent-metricsnapshot) reference it), there is no clean way to move an account between creators without losing that history; a `reassignPlatformAccount` operation is the smallest future add-back.

---

<a id="adr-0015"></a>
## ADR-0015 — Internal manual-entry provenance source

**Context.**

Under [ADR-0014](#adr-0014), operators create [`ENT-Creator`](../30-data-model/00-data-model.md#ent-creator) records and curate their [`ENT-PlatformAccount`](../30-data-model/00-data-model.md#ent-platformaccount)s by hand. `ENT-PlatformAccount` carries a **mandatory** `Provenance` envelope ([DP-002](../20-cross-cutting/00-data-principles.md)), and the envelope's `source` must name a `SRC-*` id from the closed registry in the [data source matrix](../40-integrations/00-data-source-matrix.md) — all of which are external scraper/API providers ([ADR-0001](#adr-0001)). A platform account typed in by an agency operator originates from no external provider, so no valid `source` existed for it: the operator-managed identity of ADR-0014 and the provenance doctrine of [ADR-0008](#adr-0008) could not both be satisfied. (Product-owner decision, 2026-07-06.)

**Decision.**

Register **`SRC-agency-manual-entry`** as an **internal, non-provider source id**: it marks a record whose values were entered by hand by agency staff in the CRM. It is valid anywhere a `Provenance.source` is required. It is **not** an external provider — it performs no collection and adds no capability, cost, rate limit, or ToS surface — so the frozen external-provider stack of [ADR-0001](#adr-0001) / [DP-006](../20-cross-cutting/00-data-principles.md) is unchanged in substance. For a manually-entered record, `fetchedAt` is the entry time and `sourceVersion` identifies the entry surface (for example the CRM UI revision).

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- The [data source matrix](../40-integrations/00-data-source-matrix.md) documents `SRC-agency-manual-entry` as an internal source alongside — but distinct from — the external provider registry, and the application's source registry accepts it as a valid `Provenance.source`.
- Operator-added platform accounts ([ADR-0014](#adr-0014)) carry `Provenance { source: SRC-agency-manual-entry, fetchedAt: entry time, sourceVersion: entry surface }`. The stamp records the **record's origin** and is never rewritten by later edits: an operator correction to an externally-sourced account leaves the provider provenance in place (observed values such as the follower count remain the provider's), with the change captured in the audit trail; profile sync continues to refresh provider provenance per [DP-002](../20-cross-cutting/00-data-principles.md).
- Hand-entered records stay distinguishable from scraped ones, so data-quality and audit tooling can treat manual entries explicitly rather than having them masquerade as provider data.
- `SRC-agency-manual-entry` must never be stamped on a record that **did** come from an external provider — that would launder scraper data past the ToS/cost governance anchored in provenance.
- Reintroducing automatic detection or a dedicated merge feature later requires a **superseding ADR**; automatic detection additionally requires the AC-M3-003 signal set and confidence threshold to be specified first.

---

<a id="adr-0016"></a>
## ADR-0016 — No external client access in v1 — CLIENT_VIEWER surface dropped

**Context.**

[REQ-M3-012](../90-traceability/00-req-matrix.md) and its acceptance criteria in the [Module 3 spec](../50-modules/module-3-crm-seeding.md) (the roles-section **AC-M3-019**), the module's purpose item §1.1(6) and roles requirement §2.10, the §5 service diagram's "approved reports only" edge, and the roadmap's P3/P4 lines ([80-delivery/00-roadmap.md](../80-delivery/00-roadmap.md)) all presuppose an **external client login**: a `CLIENT_VIEWER` user who signs in and sees only **approved** reports for their own brands. That surface implies a report-approval workflow, a client-facing read API, and the P4 white-label client-report deliverable built on top of it. The agency, however, will have **no external clients**: nobody outside agency staff will ever log in, so there is no consumer for the approved-report surface. (Product-owner decision, 2026-07-06.)

**Decision.**

v1 ships **no external client access**: no client accounts are created, no report content exists, and no report-approval workflow is built. `CLIENT_VIEWER` **stays a defined [`ENUM-RoleName`](../00-meta/03-glossary.md#enum-rolename) value** whose access is **deny-everything for all agency data** — it holds no internal permission, which is enforced by every policy and regression-tested as the standing negative authorization case. The P0-era **containment shell** remains wired exactly as built and reviewed in P0: an `/reports` route rendering a permanent empty state, the post-login redirect that confines a `CLIENT_VIEWER` session to that page, and the seeded `reports.view-approved` grant that opens it — the shell exposes **no report content and no agency data**; it exists so the deny-everything role has a harmless landing surface. The rest of [REQ-M3-012](../90-traceability/00-req-matrix.md) — `ADMIN`-only `User`/`Role` writes and single-role enforcement — is delivered unchanged. The P4 **"white-label client reports"** deliverable, which builds on the `CLIENT_VIEWER` behaviour, is **void unless this ADR is superseded**.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- [REQ-M3-012](../90-traceability/00-req-matrix.md) is satisfied in v1 by `ADMIN`-only `User`/`Role` management plus the deny-all `CLIENT_VIEWER` role; the roles-section **AC-M3-019** (client sees only approved brand-A reports) is **deferred out of v1** with the surface it describes.
- **No new `DEF-*` / "unavailable" surface.** Unlike deferrals that hide unavailable *data* ([ADR-0004](#adr-0004), [ADR-0005](#adr-0005)), this removes an absent *feature* for a user population that does not exist — there is simply no client-facing surface to render — following the [ADR-0014](#adr-0014) precedent.
- `CLIENT_VIEWER` remains canonical in the glossary and data model; it is not removed from [`ENUM-RoleName`](../00-meta/03-glossary.md#enum-rolename), so no enum migration is needed if a superseding ADR restores the surface.
- The `reports.view-approved` permission stays in the catalog and **remains seeded to `CLIENT_VIEWER`**, granting exactly one thing: the empty P0 containment page (`/reports`). No report entity, content, publishing, or approval flow exists behind it; making it show anything requires the superseding ADR.
- The P4 white-label client-report deliverable in the [roadmap](../80-delivery/00-roadmap.md) is void under this ADR; re-adding any external client access (login, approved reports, white-label exports) requires a **superseding ADR**.
---

<a id="adr-0017"></a>
## ADR-0017 — Provider-cost governance: refresh-window polling, batched story runs, and cost-side circuit breaking

**Context.**

Multiple canonical files flagged ingestion and enrichment **cadence as "NOT canonically decided"** (config comments in `config/qds.php`, the scheduler notes in `routes/console.php`) pending the P4 cost-governance work. A verified pricing research pass over every Apify actor in the frozen stack ([reviews/PLAN-apify-cost-optimization-2026-07-07.md](../../reviews/PLAN-apify-cost-optimization-2026-07-07.md), all prices confirmed against live primary sources on 2026-07-07) established that the as-built polling shape — every account, every cycle, newest-30 items with no date filter, one actor run per handle, stories dispatched from BOTH the full and the story-only cycles — costs ~$530/month for an illustrative 20-Instagram + 10-TikTok roster on Apify's Free tier, whose entire monthly credit ($5) it would exhaust in under seven hours. The research also verified: the actors' incremental date parameters (`onlyPostsNewerThan`, `oldestPostDateUnified`); that every TikTok video item embeds full `authorMeta` profile stats; that the story actor bills a dominant per-RUN start fee ($0.099) and accepts up to 100 usernames per run; and that the general `apify~instagram-scraper` accepts `directUrls` post URLs — while being no cheaper for feed polling. (Product-owner direction, 2026-07-07: external API sources "must be price efficient".)

**Decision.**

The polling pipeline adopts the following cost posture (all knobs env-tunable under `qds.ingestion.*`; the provider SET remains frozen per [ADR-0001](#adr-0001)/[ADR-0002](#adr-0002) — only actor INPUTS and dispatch shape change):

1. **Refresh-window polling** (default 14 days): content polls send the provider-side date filter, so a post's metrics keep refreshing while it is inside its engagement-growth window instead of re-buying the newest-30 forever. Re-polling in-window posts **is** the metric-refresh mechanism (snapshots are DB-only) — a pure "only new posts" filter is explicitly rejected as KPI-corrupting. A **full-depth sweep** (no date filter, persisted as `ingestion_cycles.full_depth`) runs at most once per `full_sweep_interval_days` (default 7) to catch late-blooming engagement.
2. **Stories leave the full cycle**: story polling belongs exclusively to the tighter story-only cycle (the double dispatch was a defect — 10 story polls/day instead of the documented 6). Story cycles fan out **batched runs** (`IngestStoriesBatchJob`, default 25 handles per run via the async run endpoint), amortizing the per-run start fee; items are attributed by owner handle and never guessed across accounts. A `stories_enabled` kill switch gates the paid story actor entirely. On-demand per-creator runs still poll stories inline (explicit human request).
3. **TikTok dispatches no profile job**: the content run's `authorMeta` feeds the same `PlatformAccountProfileSync` contract (`ProvidesProfileFromContent`); the dedicated `resultsPerPage:1` profile run was a duplicate purchase. The quiet-day profile-info item the actor pushes under a date filter refreshes the profile and is skipped as content without quarantine noise.
4. **Adaptive cadence** (defaults on): accounts whose newest known content is older than 14 days — and that have been successfully polled for at least that long — drop to one content poll per 24h; story polls require a story seen within 7 days (plus one daily probe). Creators attached to an ACTIVE/PLANNED campaign or PLANNED/ACTIVE/SHIPPING seeding run are **never demoted** — campaign EMV/CPE windows need full resolution exactly then.
5. **Cost-side circuit breaker**: a provider FAILING with a PERMANENT error category (auth/paywall/not-found) is skipped instead of re-invoked every cycle; after a cooldown (default 6h) exactly one canary probe goes through and its outcome closes or re-arms the breaker. Retries are bounded and replay-guarded: a retry never re-bills a sibling provider that already succeeded for the same correlation, and the Apify client timeout sits above Apify's 300s sync wall so a client abort cannot double-bill.
6. **Campaign-linked metric refresh**: content matched to a producing seeding campaign ([REQ-M3-008](../90-traceability/00-req-matrix.md) links) that has aged out of the refresh window is refreshed daily via `SRC-apify-instagram-scraper` **direct post URLs** (`permalink` now captured on ENT-ContentItem) — this activates the registered-but-unused general-scraper source for the one capability where it is uniquely fit, without touching roster polling. Instagram only in v1 (the TikTok actor has no per-URL input; TikTok campaign content is covered by the window and the weekly sweep).

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- The previously flagged "cadence NOT canonically decided" class is now decided for ingestion polling by this ADR; the cron expressions themselves remain env-configurable operations knobs. Enrichment sweep cadence remains flagged (unchanged by this ADR).
- Per-post metric time series gain a bounded refresh horizon: engagement changes on posts older than the window are captured by the weekly full-depth sweep and (for campaign-linked content) the daily direct-URL refresh, not every cycle. Dashboards read unchanged.
- `SRC-apify-instagram-scraper` changes from registered-but-unused to the direct-URL refresh source; provenance stamps it on refreshed records. The data-source matrix should note this activation (doc amendment pending, tracked with the Module 1 schema-deviation cluster).
- The story actor remains the documented `datavoyantlab` deviation and is currently paywalled on the org's Apify account; the breaker keeps it from billing failed runs, and the kill switch keeps it dark until an Apify plan with access exists (Starter is the researched floor).
- Dropping `apify~instagram-reel-scraper` (plan rec 5) is NOT decided here — it stays gated on the shortCode-coverage A/B described in the plan.

---

<a id="adr-0018"></a>
## ADR-0018 — Operator-assigned creator geography ahead of Module 2

**Context.**

[REQ-M2-003](../90-traceability/00-req-matrix.md) makes geographic attribution a Module 2 capability: [`ENT-GeoAttribution`](../30-data-model/00-data-model.md#ent-geoattribution) is M2-owned (ownership matrix) and its automatic, signal-based inference (**AC-M2-003a/b**) ships with phase P2 — which the product owner deferred after P1. Until then `DIM-Geo` is empty, so every geography surface (country slices on the seeding results dashboard, `ROLLUP-MetricByGeo`) renders "unavailable". The agency, however, KNOWS where many of its manually-added creators live; forcing that knowledge to wait for an AI inference module serves nobody. (Product-owner decision, 2026-07-08.)

**Decision.**

Operators may **assign a creator's geography by hand** from the CRM creator profile. The entry is the **human half of REQ-M2-003's human-in-the-loop** — not a bypass of it: each assignment is a full `ENT-GeoAttribution` row whose mandatory `ConfidenceAssessment` carries `value` = the asserted country, `confidenceLevel` HIGH, `signals` `["operator-entry"]` (the [ADR-0015](#adr-0015) manual-entry class), and `verificationStatus` **HUMAN_REVIEWED** — location is asserted, never presented as an observed fact ([DP-003](../20-cross-cutting/00-data-principles.md)). Ownership is preserved: the CRM never writes the entity — all writes go through the M2-owned **`CreatorGeography`** seam (the XMC-001/XMC-003 owner-side-contract pattern), v1 keeps **one current row per creator** (updated in place, audit-logged), and the row feeds `DIM-Geo` on the next rollup refresh so country slices light up for assigned creators.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Geography surfaces stop being uniformly "unavailable": creators WITH an assignment slice by country; creators without one still render "unavailable" — never zero (deferred-register rule).
- **AC-M2-003a (automatic inference) remains deferred with P2** and, when built, must treat operator rows per [DP-004](../20-cross-cutting/00-data-principles.md): an AI attribution never overwrites a HUMAN_REVIEWED assignment.
- `city` is stored although the canonical `ENT-GeoAttribution` shape lists only country/region — `DIM-Geo` and `ROLLUP-MetricByGeo` already model city, so the entity shape is the amendment candidate (flagged deviation).
- The `geo_attributions` FK cascades on creator deletion (lifecycle-coupled data, the XMC-003 roster precedent) — a geography row never blocks deleting a creator.
- No new `DEF-*`: nothing is removed or hidden; a deferred capability gains its manual counterpart early, following the [ADR-0015](#adr-0015) precedent.

---

<a id="adr-0019"></a>
## ADR-0019 — Multi-tenant SaaS foundation: ENT-Tenant, tenant ownership, centralized tenant context

**Context.**

QDS was built as a single agency's internal tool: every canonical entity implicitly belonged to "the agency", every natural key was globally unique, `HashtagScope.AGENCY` and the single-row monitoring-plan settings hard-coded the one-agency assumption, and no tenancy abstraction existed anywhere (verified exhaustively across code, config, schema, and tests). The product now becomes a **subscription-based SaaS**: each customer gets an isolated account containing its own users, staff, configuration, and business data (product-owner decision, 2026-07-11). Follow-up phases will add hard tenant-authorization enforcement, Stripe subscriptions with plan-based seat limits, team invitations, and adversarial isolation tests; this decision lays only the structural foundation they require.

**Decision.**

1. **Canonical tenant entity.** [`ENT-Tenant`](../30-data-model/00-data-model.md#ent-tenant) is the single tenancy abstraction — the customer organisation that owns its business data. No second abstraction (organization/workspace/team) may be introduced. `ENT-Client` remains what it always was: a CRM record describing an agency's client, one level *below* the tenant.
2. **Membership model.** One tenant has many users; **one user belongs to exactly one tenant** (`users.tenantId`, required). `users.email` stays **globally unique** — it is the login identity across the platform. The **owner** is the `ownerUserId` attribute on the tenant (exactly one; DB-enforced to belong to that tenant) — deliberately **not** a new role: [`ENUM-RoleName`](../00-meta/03-glossary.md#enum-rolename) is a closed set and permission roles stay orthogonal to ownership. Role/permission *definitions* (spatie tables) remain global; role *membership* is per-user and therefore per-tenant.
3. **Ownership map.** Every domain entity of M1/M2/M3, the enrichment/EMV registers, export jobs, and monitoring-plan settings are **tenant-owned**: a `NOT NULL tenantId` ownership key with FK to `tenants`. **Global (intentionally shared)**: framework tables, role/permission definitions, and the provider-side operational registers (`provider_calls`, `provider_health_states`, `ingestion_alerts`, `quarantined_records`, `provider_response_samples`, `ingestion_cycles`, `analytics_watermarks`, `analytics_refreshes`) — platform telemetry for a single shared provider stack. `audit_logs` carries a *nullable* tenant attribution (system actions have none). The authoritative per-table classification lives with the tenancy note in the [ownership matrix](../70-shared/00-ownership-matrix.md).
4. **Tenant-scoped natural keys.** Uniqueness that is only meaningful within one customer's roster is re-keyed under the tenant: `platform_accounts (tenantId, platform, handle)`, `content_items` / `stories` `(tenantId, platform, externalId)`, hashtag-list entries, EMV `(tenantId, formulaVersion, rateCardVersion)`, and **one ACTIVE EMV configuration per tenant** (previously a database-wide singleton). Consequence accepted and documented: two tenants tracking the same public handle each ingest and pay for that data independently (cross-tenant dedup would be a covert data leak; a shared-ingestion optimisation would need its own ADR).
5. **Structural cross-tenant safety.** Every parent referenced by tenant-owned rows carries `UNIQUE (id, tenantId)`, and every FK between tenant-owned tables gains a composite `(fk, tenantId) → parent (id, tenantId)` constraint — a campaign in tenant 1 linking a creator in tenant 2 is rejected by the database itself, not merely by application checks. The owner-membership invariant is the same mechanism: `tenants (ownerUserId, id) → users (id, tenantId)`.
6. **Centralized tenant context.** A scoped `TenantContext` binding (one instance per request / per job, framework-flushed) is the sole way code answers "whose data?": HTTP requests bind it from the authenticated user (middleware), queued jobs inherit the dispatcher's tenant through the queue payload and restore it around `handle()` (push/pop, sync-queue safe), and platform pipelines that legitimately span tenants (monitoring cycles, snapshots, enrichment sweeps) establish it **per unit of work** from the aggregate root they process via `runAs`. Tenant-owned models auto-stamp `tenantId` from the context on create and are query-scoped to it whenever a context is active; with no context and no explicit tenant the INSERT fails — **ownership is never guessed**. New object-storage writes are prefixed `tenants/{id}/…`.
7. **Analytics.** All five `FACT-*` tables and the entity dims (`DIM-Creator`, `DIM-Client`, `DIM-Brand`, `DIM-Product`, `DIM-Campaign`, `DIM-SeedingCampaign`, `DIM-Geo`) carry `tenantId` sourced from their OLTP rows; every `ROLLUP-*` groups by tenant and includes it in its uniqueness key. `DIM-Date` and the enum dims stay global. Dashboards/readers are unchanged in this phase (see deferrals).
8. **Migration/backfill doctrine.** A pre-tenancy database contains exactly one implicit customer: migrations create a **founding tenant** ("Question de Style"), assign every existing row to it via catalog-default backfill (append-only triggers never fire), and set its owner to the earliest active ADMIN. A table with rows but no tenant aborts the migration loudly — silent ownership assignment is prohibited.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- The single-write-owner law of the [ownership matrix](../70-shared/00-ownership-matrix.md) is unchanged *within* a tenant; `ENT-Tenant` itself is written by M3's admin surface (matrix row added). The write-path contracts (XMC-\*) now implicitly carry the tenant of their triggering context.
- **Explicitly deferred to follow-up phases** (do not build ahead): hard tenant-authorization enforcement on every read path (dashboards/`RollupReader` currently rely on FK-disjointness plus the context scope, not on enforced filters), Stripe subscriptions and plan-based seat limits (columns intentionally absent from `ENT-Tenant`), team invitations/self-service signup (users are still admin-provisioned), per-tenant provider telemetry and cost attribution, per-tenant cadence resolution inside the shared monitoring cycles, and tenant-lifecycle tooling (tenant offboarding/export). Cross-tenant **isolation is not claimed** until the enforcement phase proves it adversarially.
- Test infrastructure provisions isolated tenants by default (every database-backed test runs inside a fresh tenant context; a shared helper provisions Tenant A/B pairs with owners), so the later adversarial phase has its fixtures ready.
- The scraping cost model becomes per-tenant: overlapping rosters multiply provider spend ([ADR-0017](#adr-0017) governance applies per tenant's roster; the breaker and provider health stay platform-global because the Apify account is shared).
- `HashtagScope.AGENCY` now means "tenant-wide" (the entry key is tenant-scoped); the glossary term is amended rather than renamed to avoid a breaking enum migration.

<a id="adr-0020"></a>
## ADR-0020 — Hard tenant isolation: fail-closed authorization backstop, scoped analytics reads, tenant-aware validation

**Context.**

[ADR-0019](#adr-0019) built the structural tenancy foundation but explicitly **deferred hard enforcement and did not claim cross-tenant isolation** — dashboards and `RollupReader` read rollups unscoped, all 30 model policies gated on *permission only* (never comparing the model's tenant to the actor's), `Rule::exists` foreign-key validators ran unscoped, and the archived-story-media stream route resolved its model guest-and-context-less behind a signature alone. A pre-implementation adversarial audit (13 surface auditors over routes/bindings, Livewire, policies, analytics, exports, jobs, cache, mass-assignment, relationships, auth) confirmed the read-path leaks were concretely exploitable by a fully-privileged tenant ADMIN on default page loads. This ADR closes those gaps so that **a user of Tenant A can never read, query, infer, modify, delete, export, download, attach, or aggregate Tenant B data**, and proves it adversarially.

**Decision.**

1. **Fail-closed authorization backstop.** A single `Gate::before` hook ([`TenantIsolationGate`](../../app/Shared/Authorization/TenantIsolationGate.php), registered by `AuthServiceProvider`) denies **any** ability whose arguments include a `BelongsToTenant` model whose `tenantId` differs from the acting user's — ahead of every policy and permission check, and regardless of role. It only ever **denies or defers** (never grants), so the permission-only policies keep deciding same-tenant access unchanged. This makes all 30 policies tenant-aware centrally (tenant ownership and permission stay *separate* concerns) and holds even if a model is ever loaded outside a bound context (raw query, unscoped find, middleware regression). This is defense in depth **on top of** the ADR-0019 route-model-binding scope, not a replacement for it.
2. **Analytics reads are tenant-scoped at the source.** `RollupReader` injects `TenantContext` and filters **every** `rollup_*` / `dim_*` query by the active tenant (guarded — platform/null context reads unfiltered), mirroring the already-correct `ReportBuilder`. Every remaining raw analytics read reachable from an authenticated request (the `dim_geo` city pickers in `SeedingResultsDashboard` and `ReportFilters`, the `emv_results` sub-query in `EmvCalculator`) is likewise scoped. Tenant B rows can no longer affect Tenant A's dashboard totals, charts, plan-page estimates, or exports.
3. **Tenant-aware validation.** `TenantRule::exists()` replaces `Rule::exists` on every tenant-owned foreign-key validator across the CRM and Monitoring Livewire surfaces, so a forged foreign id fails as a clean, uniform validation error (never a raw composite-FK 500) and is no longer a cross-tenant existence oracle. `users.email` uniqueness stays global by ADR-0019 (login identity spans tenants).
4. **Media/export/file access.** The archived-story-media **stream** route is `['web','auth','signed']` and re-authorizes via `StoryPolicy` inside the controller, so the signature is one factor rather than the sole bearer credential for another tenant's private media. Signed export/download URLs for a foreign artifact 404 at the scoped route binding regardless of a valid signature.
5. **Alerts: per-tenant vs global, explicit.** Tenant attribution on `IngestionAlert` is an **explicit opt-in** (a nullable `tenantId`, passed by the caller — never read from ambient context): the P4 data-quality scan (which embeds a tenant's own creator handles) runs per tenant under `runAs` and passes its tenant, while provider-level incidents raised from inside tenant-bound ingestion jobs stay **global** (one deduped alert, visible to all). The operations dashboard shows an operator only their own roster alerts plus the global provider ones; its snapshot/story freshness reads are tenant-scoped.
6. **Non-forgeable ownership stamps.** `AuditLog.tenantId`/`userId` are removed from `$fillable` and force-filled from trusted server state (`TenantContext` / `Auth` / the export-job row), so the audit trail's attribution can never be mass-assigned. No `BelongsToTenant` model exposes `tenantId` to mass assignment (enforced by an architecture test).
7. **Scheduled/queued tenant work is explicit.** Multi-tenant scheduled work initializes context → processes one tenant → restores (`runAs`), isolating one tenant's failure from the rest (data-quality scan, task-reminder audit stamping).

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Isolation is now **claimed and proven adversarially**: `tests/Feature/Tenancy/` holds a cross-tenant suite (authorization backstop, HTTP/IDOR 404s, analytics non-influence through a real rollup refresh, tenant-id forgery + oracle parity, per-tenant vs global alerts) plus a `TenantIsolationArchitectureTest` that fails if a future model exposes `tenantId` to mass assignment or a parameter-binding web route drops `auth`.
- The backstop composes with, but does not weaken, existing authorization — permission checks, owner-only export downloads (`ExportJobPolicy`), and the `CLIENT_VIEWER` deny-everything posture ([ADR-0016](#adr-0016)) are unchanged for same-tenant access.
- **Still deferred** (unchanged from ADR-0019, do not build ahead): Stripe subscriptions / plan-based seat limits, team invitations / self-service signup, per-tenant provider **cost** attribution, per-tenant cadence resolution inside shared monitoring cycles, and tenant-lifecycle tooling (offboarding/export). A dedicated **platform-operator** capability distinct from tenant ADMIN (for the genuinely global provider/queue telemetry still visible on the operations dashboard) is noted as the next hardening step but is out of this ADR's data-isolation scope.
- Adding `auth` to the story-media stream route makes a leaked/shared signed URL insufficient on its own — the media element request must carry the viewer's session, which is the intended tightening.

---

<a id="adr-0021"></a>
## ADR-0021 — Stripe SaaS billing: tenant-as-customer, plan catalog, seat limits, secure team invitations

**Context.**

[ADR-0019](#adr-0019) made `ENT-Tenant` the single customer-account abstraction and [ADR-0020](#adr-0020) proved hard cross-tenant isolation, but both explicitly deferred the commercial layer: Stripe subscriptions and plan-based seat limits ("columns intentionally absent from `ENT-Tenant`") and team invitations (users admin-provisioned only). This ADR is the sanctioned closer of those deferrals (product-owner direction, 2026-07-12: SaaS pivot Prompt 3). Stripe is a **payment processor, not a data provider** — it sits outside the frozen [ADR-0001](#adr-0001) `SRC-*` stack and outside the data-source matrix, and this ADR is its explicit authorization. Laravel Cashier was evaluated and **rejected**: the codebase's integration doctrine is SDK-free hand-rolled HTTP clients with sanitized failure envelopes and `Http::fake` offline tests (Apify/Google precedent), and Cashier's schema cannot carry the ADR-0019 tenancy conventions (NOT-NULL `tenantId`, composite FKs, non-mass-assignable ownership stamps) that `TenantIsolationArchitectureTest` enforces.

**Decision.**

1. **The tenant is the billable customer.** One tenant ↔ one Stripe customer (`tenants.stripeCustomerId`, unique, not mass assignable, force-filled once under a row lock with a deterministic Stripe idempotency key). Staff users are never separate Stripe customers. Webhooks resolve the tenant **only** through this stored mapping — payload metadata is written for reconciliation but never trusted.
2. **Thin, hosted-surface Stripe integration.** A concrete `StripeClient` on the `Http` facade (secret only from env, only in headers, failures rethrown as sanitized `StripeApiException`) with exactly four calls: create customer, create Checkout session, create Billing-Portal session, get subscription. Every card-touching and plan-mutating interaction happens on Stripe-hosted pages (Checkout for the first subscription, the Billing Portal for payment methods/invoices/plan changes/cancellation); state returns exclusively through verified webhooks. QDS stores no payment data and logs identifiers only.
3. **Configurable plan catalog.** `ENT-SubscriptionPlan` is a **global** table (the spatie-definitions class of ADR-0019 §2) synced idempotently from `config/billing.php` by `qds:billing-sync-plans` (and the seeder): code, name, Stripe price id, `ENUM-BillingInterval`, `maxSeats`, feature flags, active state. Plan gating reads the catalog — never `if ($plan === 'professional')` literals. Commercial values (price ids, seat counts) come from the environment; final numbers are a pending product-owner decision.
4. **Subscription state mirrors Stripe.** `ENT-TenantSubscription` (tenant-owned, NOT-NULL `tenantId`) carries `ENUM-SubscriptionStatus` = Stripe's canonical states (no invented lifecycle). The webhook `SubscriptionSynchronizer` is its **only writer**: signature-verified (offline HMAC, tolerance-bounded), idempotent via a global `stripe_events` insert-in-transaction ledger (a failed run rolls its dedup row back so Stripe's retry reprocesses), out-of-order-safe via an `event.created` watermark per subscription, and per-tenant writes run under `runAs` (ADR-0020 §7). At most one live (non-terminal) subscription per tenant (partial unique index); a newly created live subscription supersedes a stale live row.
5. **Access per state, enforced server-side.** ACTIVE and TRIALING are fully entitled; PAST_DUE keeps full access as the dunning grace window; every other state (or no subscription) blocks the product surfaces via the `subscribed` middleware on the dashboard/reports/module route groups — while the account, billing, team, and auth surfaces stay reachable for recovery. **Tenant data is never deleted or mutated by any subscription state.** Enforcement is gated on `QDS_BILLING_ENFORCED` (default off — the founding tenant predates billing; flipping it on is an explicit operator action once its subscription exists).
6. **Seat model and concurrency-safe enforcement.** Every **active** user consumes one seat, including the owner; deactivated users and pending invitations consume none — acceptance re-checks availability atomically instead of reserving seats. Effective allowance = per-tenant `seatsOverride` ?? plan `maxSeats`. Every seat-consuming mutation (create active user, reactivate, bulk-activate, accept invitation) funnels through `SeatLimiter::reserve()`: one transaction, `SELECT … FOR UPDATE` on the tenant row as the per-tenant serialization point, recount after the mutation, roll back on violation — `count()`-then-`insert()` without the lock is forbidden and the mechanism is proven with a real two-connection lock/interleaving test. **Downgrades below current usage are allowed and never remove members**: the tenant becomes over-limit, which deterministically blocks further seat-consuming team changes until active members fit the limit.
7. **Secure tenant-bound invitations.** `ENT-TeamInvitation` (tenant-owned; composite tenant FKs to the inviter and accepted user) stores only the SHA-256 hash of a 256-bit single-use token; expiry is config-bound (default 7 days); one pending invitation per (tenant, email) via a partial unique index. Issuance requires `users.manage` and a staff `ENUM-RoleName` (never `CLIENT_VIEWER` — [ADR-0016](#adr-0016) stands). Acceptance is a guest flow shaped like `reset-password/{token}`: the account is created with the **invited** email (no email input to tamper with), inside the seat lock, with single-use consumption re-checked `FOR UPDATE`; a globally registered email cannot accept (one user ↔ one tenant, ADR-0019).
8. **Owner-only billing authority.** Billing actions (checkout, portal, billing page) are gated by a defined `billing.manage` gate — true only for `tenants.ownerUserId` — deliberately **not** a new role (`ENUM-RoleName` stays closed) and not a permission row. Team management stays on the existing `users.manage` ADMIN permission; the users page gains the invitation panel and seat banner instead of a duplicate team surface.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- New entities `ENT-SubscriptionPlan` (global), `ENT-TenantSubscription`, `ENT-TeamInvitation` (tenant-owned) plus the global `stripe_events` idempotency ledger enter the data model and ownership matrix; `ENT-Tenant` gains `stripeCustomerId`. New enums `ENUM-SubscriptionStatus`, `ENUM-BillingInterval` enter the glossary.
- The Billing module (`app/Modules/Billing/`) is commercial infrastructure, not a fourth product module — the vision-and-scope "exactly three product modules" law is unchanged.
- The test suite proves: lifecycle sync (creation/upgrade/downgrade/cancellation/expiry/failure/recovery, out-of-order and replay), transport-level webhook security (signature, tolerance, dedup, failure-rollback-then-retry), seat invariants at every enforcement point including a genuine two-connection race, invitation token security (expiry/replay/revocation/email binding/tenant binding), owner-vs-member authorization, cross-tenant billing/team isolation, and the full middleware state matrix — all offline (`Http::fake` + locally-computed HMAC signatures).
- `CLIENT_VIEWER` sessions of a lapsed tenant dead-end (redirected off /reports to an account page they cannot open) — accepted for v1 since no client accounts exist ([ADR-0016](#adr-0016)).
- **Still deferred (do not build ahead):** per-seat (quantity-based) Stripe pricing and licensed-seat sync, in-app plan-change UI beyond Checkout/Portal, dunning email sequences (Stripe's own dunning emails apply), per-tenant provider **cost** attribution, per-tenant cadence resolution, tenant-lifecycle tooling (offboarding/export), and the platform-operator capability noted in ADR-0020.

---

<a id="adr-0022"></a>
## ADR-0022 — Estimated-reach method: a per-tenant configurable linear model

**Context.**

The metrics catalog ([MET-EstimatedReach](../30-data-model/00-data-model.md), REQ-M1-006) has always promised estimated reach "modeled from PUBLIC views/plays and follower signals via a documented method" — but **no method was ever documented**. `ReachEstimator` was therefore bound to `UnavailableReachEstimator` (returns null) and every reach surface rendered "unavailable"; the missing reach formula is a decision flagged across the schema-deviation register and the module-1 spec. Product-owner direction (2026-07-14) is to make estimated reach available through an operator-configurable, per-tenant formula managed in **Settings → Reach**, mirroring the EMV configuration discipline of [ADR-0006](#adr-0006)/REQ-M1-011 — this ADR governs the *method*; the weights are user-configured and versioned. [ADR-0006](#adr-0006) tiering stands and CONFIRMED unique reach ([DEF-003](../20-cross-cutting/01-deferred-register.md)) is unaffected.

**Decision.**

1. **Controlled linear model.** `estimated_reach = round(view_weight × views + follower_weight × follower_count)`, computed per `ENT-ContentItem` from its PUBLIC `views` (falling back to `plays`) and the author `ENT-PlatformAccount`'s `follower_count`, with optional per-platform weight overrides. No arbitrary expressions are parsed — only this structure.
2. **Always ESTIMATED, always disclosed, never a view count.** The result is a `ReachEstimate` at tier **ESTIMATED** carrying a stated `method` (`"<config method label> v<formula_version>"`); it is never presented as fact ([DP-001](../20-cross-cutting/00-data-principles.md)) and never equals a raw view count — `follower_weight` must be `> 0` and a views-only passthrough (`view_weight ≥ 1` with `follower_weight ≤ 0`, including a platform override's *effective* weights) is rejected by validation ([GL-PublicViews](../00-meta/03-glossary.md)).
3. **Tenant-owned, versioned configuration.** `ENT-ReachConfiguration` (tenant-owned, NOT-NULL `tenantId`) has the EMV lifecycle — DRAFT → ACTIVE → INACTIVE/ARCHIVED, at most one ACTIVE per tenant (partial unique index), edited only in DRAFT so activated versions stay immutable — managed by the `reach.manage` permission (ADMIN) on the Settings → Reach page. Computed figures are stored append-only as `ENT-ReachResult` rows snapshotting the configuration id, formula version, inputs, and the `ReachEstimate` envelope, so activating a new formula never rewrites past reach.
4. **Computed once, read from rollups.** Reach is computed during enrichment (a `reach` stage in SVC-EnrichmentAI, after EMV) and stored; the analytics fact loaders stamp the stored value into `fact_mention` / `fact_seeding_content`, and the existing rollups sum it. Reach is read only from the pre-aggregated rollups ([ADR-0010](#adr-0010)), never recomputed per viewer. There is **no per-user formula** — one canonical tenant figure, identical across dashboards and exports.
5. **CONFIRMED reach still deferred, and no longer surfaced as a placeholder.** CONFIRMED / true-unique reach remains out of scope ([DEF-003](../20-cross-cutting/01-deferred-register.md), [ADR-0006](#adr-0006)/[ADR-0007](#adr-0007)) — it requires authorized private analytics. Per product-owner direction its dedicated UI tiles are **removed** rather than shown as a perpetual "unavailable" placeholder; the unavailable-never-empty rule continues to apply to ESTIMATED reach when no configuration is active.
6. **Tunable defaults.** The seed defaults (`view_weight` 0.7, `follower_weight` 0.1) are starting fixtures, not doctrine; the operator tunes them per tenant. Estimated reach stays UNAVAILABLE (null, never zero) until a tenant activates a valid configuration.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- New entities `ENT-ReachConfiguration` (tenant-owned, versioned) and `ENT-ReachResult` (append-only) enter the data model and ownership matrix, mirroring the EMV configuration/result pair; the `ReachEstimator` DI binding moves from `UnavailableReachEstimator` to `DefaultReachEstimator`.
- Resolves the long-standing "reach formula" missing-decision flagged in the schema-deviation register and the module-1 reach-method gap; MET-EstimatedReach now has its promised documented method.
- The deferred register is amended in spirit: CONFIRMED unique reach stays deferred, but ESTIMATED reach is no longer perpetually unavailable. The two CRM "True unique reach" tiles and the Monitoring "Confirmed unique reach" tile are removed, and export disclosure lines are reworded to keep the DEF-003 clause only for CONFIRMED reach.
- Downstream tier propagation (e.g. REQ-M3-009 CPM) inherits ESTIMATED from a reach input via the weakest-input rule — a reach-based ratio is ESTIMATED, never laundered into a fact.
- **Still deferred (do not build ahead):** CONFIRMED unique reach / impressions ([DEF-003](../20-cross-cutting/01-deferred-register.md)/DEF-004), per-user or per-content nonlinear reach models, and an EMV-style "producing configurations" reach-disclosure panel in the CRM results surfaces beyond the stored method string.

<a id="adr-0023"></a>
## ADR-0023 — Enrichment triggers per data pull; sweep demoted to backstop

**Context.**

Since P1, the ONLY enrichment trigger was the recurring sweep (`qds:run-enrichment`), and its cadence was explicitly flagged "NOT canonically decided" — [ADR-0017](#adr-0017) closed the ingestion-polling cadence gap but left enrichment cadence open. Product-owner decision (2026-07-17): the AI check follows the data pull — there is no separate timer to tune.

**Decision.**

1. **Content:** when an ingestion pull persists a batch, the persister reports the rows it CREATED and ingestion dispatches one enrichment job per created row — gated on `qds.enrichment.enabled` and on the sweep's `content_window_days` eligibility window (deep backfills of old posts never trigger recognition cost). Metric refreshes of existing rows NEVER re-trigger enrichment: EMV/reach results are append-only and recognition calls re-bill.
2. **Stories:** the enrichment job is dispatched after the story's media archive succeeds (recognition needs the stored file); stories without media are left to the backstop sweep.
3. **The sweep stays scheduled as recovery backstop.** Its RUNNING/COMPLETED eligibility predicate makes it a no-op for per-pull-enriched targets; it re-collects targets whose run crashed or was reaped (stale-run reaper). Its cron is an operational knob of the backstop, not a product cadence.
4. The `qds.enrichment.enabled` kill switch gates every dispatch site (per-pull and sweep alike).

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Closes the flagged "enrichment sweep cadence" missing decision (config/qds.php and routes/console.php comments are reconciled).
- Enrichment latency now tracks ingestion cadence; recognition cost per pull is bounded by created-rows × window eligibility.
- Failure recovery is unchanged: failed/reaped runs re-enter through the backstop sweep.

<a id="adr-0024"></a>
## ADR-0024 — Engagement-trend formula; posting frequency stays undecided

**Context.**

`DerivedMetricsService::engagementTrend()` and `postingFrequency()` were honest NULL boundaries — "NO canonical formula exists … do not invent one here." Product-owner decision (2026-07-17): show the engagement trend with a defined formula and a configurable window; keep posting frequency hidden.

**Decision.**

1. **MET-EngagementTrend (DERIVED):** for a creator across their platform accounts, mean observed `likes + comments` per ContentItem published in the last N days vs the N days before, reported as a whole signed percent change. An item with NEITHER metric observed is excluded (missing is never zero); a single observed component counts as-is.
2. **Unavailable, never fabricated:** NULL when either window has no included content or the previous average is zero.
3. **N is per-tenant** — Settings → Monitoring, default 30 ([ADR-0025](#adr-0025)).
4. **Read-path exception to [ADR-0010](#adr-0010):** rolling last-N-day windows cannot be served by the calendar-grain rollup matviews; the creator page computes the trend live over `ContentItem.public_metrics`, following the pre-existing sanctioned precedent of the page's average/median views.
5. **Posting frequency remains undecided** — the NULL boundary and its Unavailable surfaces stay.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Creator detail gains an "Engagement trend" DERIVED tile; exports/lists/analytics schema are unchanged in this iteration.
- The metrics catalog measure named without a formula (engagement trend) now has one; `posting_frequency` stays NULL end-to-end.

<a id="adr-0025"></a>
## ADR-0025 — Per-tenant monitoring settings & retention policy

**Context.**

Four operational values were global env-config flagged "NOT canonically decided": the shipment attribution window (60d), story-media retention (180d), communication-log retention (0), and (new with [ADR-0024](#adr-0024)) the trend window. The P4 review listed the two retention periods as ADR candidates. Product-owner decision (2026-07-17): make all four per-tenant, user-editable settings with the current values as defaults; message history keeps forever by default.

**Decision.**

1. **Storage:** append-only `monitoring_settings` (NOT NULL `tenant_id`, latest row per tenant wins, `updated_by` audit, DB CHECK ranges). Saves insert new rows — history is never edited.
2. **Page:** Settings → Monitoring (staff view via `settings.view`; saving via new ADMIN permission `monitoring-settings.manage`), four plain-language cards. Retention cards expose an on/off toggle where OFF stores 0 = keep forever; the attribution and trend windows have no off-state.
3. **Reads:** only through `MonitoringSettingsResolver` — active-context reads resolve the tenant's latest row; tenant-less reads return the config default and NEVER another tenant's row (the `MonitoringPlanSetting::current()` cross-tenant limitation must not be repeated). Explicit `…For(tenantId)` getters serve tenant-less schedulers.
4. **Retention enforcement is per-tenant:** the story-media and communication-log prune commands iterate tenants, resolve each tenant's keep-time (0 skips), and delete with explicit `tenant_id` predicates.
5. **Canonical defaults:** shipment window 60 days; trend window 30 days; story media 180 days; communication logs 0 (keep forever). The env values remain as fallbacks for tenants that never saved settings.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- Closes the flagged shipment-window gap and the P4-review retention ADR candidates; the config comments are reconciled to "DEFAULT/fallback" semantics.
- `MentionClassifier` resolves its window per tenant at classification time; the same evidence may classify differently across tenants by design.
- New operational register `monitoring_settings` (tenant-owned, append-only) joins the data model's operational-registers section.

<a id="adr-0026"></a>
## ADR-0026 — Operational confirmations: confidence cut-points, PAID, sentiment

**Context.**

Three long-flagged open decisions needed an owner call, gathered 2026-07-17.

**Decision.**

1. **Confidence cut-points are canonical at 0.85 / 0.60** (provider score ≥ 0.85 → HIGH; ≥ 0.60 → MEDIUM; else LOW → review per DP-004). They stay env-tunable (`QDS_ENRICHMENT_CONFIDENCE_*`) as operational calibration, no longer a missing decision.
2. **`PAID` stays in [`ENUM-MentionType`](../00-meta/03-glossary.md#enum-mentiontype) as an inert compatibility value.** QDS works with unpaid organic seeding only; `PAID` is asserted exclusively from a platform paid-partnership label (AC-M1-003) and never inferred. It is kept for possible future use — resolving roadmap post-P1 TODO #1 with the "keep inert" option.
3. **Sentiment (REQ-M1-009) deliberately remains Unavailable.** No NLP model/provider is chosen; the `UnavailableSentimentClassifier` binding stands. Choosing a model is a future ADR — sentiment is "off for now" by product decision, not an oversight.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- ConfidenceScore/config comments drop the "flagged missing decision" wording and cite this ADR.
- The glossary PAID row gains the inert-compatibility note; AC-M1-002/003 stand unchanged.
- Sentiment surfaces keep rendering "unavailable" honestly until a model ADR supersedes point 3.

## ADR-0027 — CRM lifecycle & restriction hardening (Stage D)

**Context.**

The CRM UX redesign audit (`docs/superpowers/specs/2026-07-16-crm-ux-redesign-audit-and-plan.md`) surfaced data-model and enforcement gaps: brand no-go lists matched a brand's canonical name only (F06), the BLOCKLISTED relationship status blocked nothing (F06), a campaign's brand could be changed out from under its seeding runs and silently corrupt their denormalized `brand_id` (F14), tasks and communication logs could not anchor to a seeding run (F18), campaigns had no brief, and statuses were free-pick labels with no progress or next-step guidance (F07/F27/F24). Stage D closes these. Three of the changes alter behaviour that existed before, so they are recorded here as a release note. (The F03 seeding-roster/shipment self-heal shipped earlier in Stage A and is unchanged.)

**Decision.**

1. **Brand no-go matching now folds a brand's aliases**, not just its canonical name. `BrandRestrictionGuard` builds one folded needle set (name + `brands.aliases`, `mb_strtolower(trim(...))`) shared by the throwing path and the bulk matchers, so they cannot diverge. The typed-name path used by the campaign wizard (where the brand may not exist yet) stays name-only by design. Case-folding stays in PHP, never SQL `lower()`.
2. **The BLOCKLISTED relationship status is now enforced at attach**, where it was previously display-only. A creator marked "do not contact or book" is skipped when added through a bulk roster path (campaign/seeding picker, copy-campaign-roster, the campaign wizard) with a distinct notice, and hard-blocked as a shipment recipient. The soft-skip / hard-block asymmetry mirrors the existing brand-restriction handling.
3. **A campaign's brand can no longer be changed while it has seeding runs.** The rule lives in a new `CampaignWriter` service (the choke point both the edit form and the wizard write through), which throws `CampaignBrandLocked` — surfaced as a validation error naming the run count. Block-and-tell, never cascade: child `brand_id`s are never auto-rewritten; the operator moves or re-brands the runs first.
4. **Additive, zero-downtime schema** (nullable): `tasks.seeding_campaign_id`, `communication_logs.seeding_campaign_id` (both with composite `(col, tenant_id) → seeding_campaigns(id, tenant_id)` FKs per ADR-0019), and `campaigns.objective` (text) + `campaigns.markets` (jsonb). No data migration.
5. **Statuses gain read-only progress and one-click suggestions, never automation.** A seeding run shows a live progress strip (roster/shipped/delivered/posted, computed from the Shipment table, not rollups). Campaign and seeding detail pages offer at most one next-step prompt (mark Planned/start/complete; close a fully-delivered run), each a confirmed one-click action behind `crm.manage`; logging an outbound message to a new contact suggests marking them Contacted; entering a shipment date ahead of its status suggests advancing it. Every prompt is optional and re-checks its trigger before acting.

**Status.** APPROVED ([`ENUM-DocStatus`](../00-meta/03-glossary.md#enum-docstatus)).

**Consequences.**

- **Release note (behaviour changes):** (1) attaches that previously succeeded only because a creator's no-go list named an *alias* rather than the canonical brand name are now blocked; (2) blocklisted creators that used to attach are now skipped/blocked; (3) editing a campaign's brand now fails while it has seeding runs. All three are intended corrections of audit-flagged defects, not regressions.
- No new permission classes; new writes stay behind `crm.manage`, reads behind `crm.view`.
- Two tests that asserted the old "blocklisted is flagged but still selectable" behaviour were rewritten to assert the skip; the campaign-brand-change guard added its own regression coverage.
- The comms-log write path still records no audit event (a pre-existing gap, unchanged here) — flagged for a later hardening pass.
