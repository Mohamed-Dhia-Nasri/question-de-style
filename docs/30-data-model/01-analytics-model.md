---
id: DOC-AnalyticsModel
title: QDS Analytics Model — Facts, Dimensions, and Rollups
status: APPROVED
canonical_for:
  - analytics-model
  - FACT-*
  - DIM-*
  - ROLLUP-*
depends_on:
  - docs/30-data-model/00-data-model.md
  - docs/00-meta/03-glossary.md
  - docs/70-shared/00-ownership-matrix.md
  - docs/20-cross-cutting/00-data-principles.md
  - docs/05-decisions/decision-log.md
  - DP-001
  - ADR-0010
  - ADR-0008
last_reviewed: 2026-07-03
---

# QDS Analytics Model — Facts, Dimensions, and Rollups

This file is the **single home for the analytical (OLAP) shape** of QDS: the dimensional star schema, the fact tables (`FACT-*`), the dimensions (`DIM-*`), and the pre-aggregated rollups (`ROLLUP-*`). The operational (OLTP) entity shapes remain canonical in [`00-data-model.md`](00-data-model.md); this file defines how those entities are **measured, aggregated, and tracked over time** across weeks/months/years, brands, products, content types, cities, and countries.

The engine and strategy are frozen in [ADR-0010](../05-decisions/decision-log.md#adr-0010) (engine: **Neon Postgres**, per [ADR-0013](../05-decisions/decision-log.md#adr-0013)). Read those first.

Scope guardrails:

- **Entity shapes are not restated here.** Fields of `ENT-*` are canonical in [`00-data-model.md`](00-data-model.md); this file links to them.
- **Enums are referenced by name only** (canonical in [`../00-meta/03-glossary.md`](../00-meta/03-glossary.md)).
- **Facts and rollups are derived, not owned by a module.** Like the envelopes, they have no module write-owner; they are computed and maintained by `SVC-Analytics` (see [system architecture](../60-architecture/00-system-architecture.md)) from the operational entities. Write-ownership of the source entities stays canonical in [`../70-shared/00-ownership-matrix.md`](../70-shared/00-ownership-matrix.md).

---

## 1. Why a separate model (OLTP vs OLAP)

QDS has two workloads with opposite needs:

- **Operational (OLTP)** — create/read/update single records: a shipment, a creator, a campaign. Served by the normalized entities in [`00-data-model.md`](00-data-model.md).
- **Analytical (OLAP)** — aggregate huge numbers of measurements across many dimensions and time grains: "total reach of Product X across all seeded creators this quarter", "EMV per brand per country per month". Served by **this** dimensional model.

Trying to answer analytical questions off the normalized tables does not scale. The rule: **measurements are written once as immutable facts; every reported number is read from a pre-aggregated rollup, never computed live over raw rows.** This also matches the provenance-first doctrine ([ADR-0008](../05-decisions/decision-log.md#adr-0008)) — facts are append-only and never mutated.

---

## 2. Engine & modeling principles (per [ADR-0010](../05-decisions/decision-log.md#adr-0010))

1. **Engine:** **Neon Postgres** (serverless Postgres, EU region; see [ADR-0013](../05-decisions/decision-log.md#adr-0013)). Fact tables use **native declarative partitioning** by time. Rollups are **materialized views / rollup tables refreshed on a schedule** by `SVC-Analytics` — Neon supports neither the TimescaleDB extension nor `pg_cron`, so the refresh is driven by the app scheduler (Laravel Scheduler → Horizon), not the database. This keeps analytics inside the same Postgres used for OLTP — no second database in v1.
2. **Star schema:** central `FACT-*` tables reference `DIM-*` dimension tables by key. No fact-to-fact joins in reports.
3. **Append-only facts:** a fact row is written once from a source event/snapshot and never updated. History is the accumulation of facts, not mutation.

   > **Amended 2026-07-07 — as-built reconciliation (gated carve-out to [ADR-0010](../05-decisions/decision-log.md#adr-0010)'s append-only rule):** two fact sources mutate *after* their fact row is stamped — `ENT-Shipment` (`posted`/`postedAt` flip when content matching links a reel, §6 step 2) and `ENT-Mention` (`campaignId` is attributed **after** detection, always later than the mention's fact load). For **these two tables only**, the append-only guard admits a **gated `DELETE` — never `UPDATE`**: the delete passes only while the table's *own* transaction-local setting is `'on'` (`qds.analytics_shipment_restamp` for `FACT-Shipment`, `qds.analytics_mention_restamp` for `FACT-Mention`; one gate never loosens the other), set and cleared by the corresponding `SVC-Analytics` loader immediately around its purge statement, inside the refresh transaction. Mutated source rows are re-stamped by **DELETE + fresh INSERT** past a per-table `updated_at` watermark; the gate also covers the tables' time partitions. Every other operation on every fact table still raises. Without the `FACT-Mention` restamp, a stamp-once row would permanently exclude late-attributed mentions from `ROLLUP-MentionByCampaign` (§5) and campaign results would systematically undercount.
4. **Grain is explicit:** every fact table declares exactly what one row represents. Never mix grains.
5. **Tier-aware aggregation (binding, from [DP-001](../20-cross-cutting/00-data-principles.md#dp-001)):** every measure carries its [`ENUM-MetricTier`](../00-meta/03-glossary.md#enum-metrictier). A rollup **must not blend tiers into one number that reads as fact** — e.g. a total that includes `ESTIMATED` reach is itself `ESTIMATED` and must be labelled so. `DIM-MetricTier` exists precisely so aggregates can be filtered/labelled by tier.
6. **Never sum a `DERIVED` ratio.** Engagement/view/comment rates and post-rate are ratios; to aggregate them, sum their `PUBLIC` components and **recompute** the ratio at the target grain. Only additive measures (counts, views, likes, EMV amount, estimated-reach amount) are summed.
7. **Escape hatch:** if fact volume outgrows Neon Postgres (hundreds of millions+ rows with ad-hoc slicing), the same star schema ports to a columnar engine (**ClickHouse**) via CDC — a lift, not a rewrite. Design for it now; adopt only when needed ([ADR-0010](../05-decisions/decision-log.md#adr-0010)).

---

> **Tenancy (amended 2026-07-11, [ADR-0019](../05-decisions/decision-log.md#adr-0019)).** Every `FACT-*` table and every entity dimension (`DIM-Creator`, `DIM-Client`, `DIM-Brand`, `DIM-Product`, `DIM-Campaign`, `DIM-SeedingCampaign`, `DIM-Geo`) carries the `tenantId` ownership key of its source entity; every `ROLLUP-*` groups by tenant and includes it in its uniqueness key. `DIM-Date` and the enum dimensions stay global (shared vocabulary, no tenant data). Facts remain FK-less by design — tenant agreement is guaranteed upstream by the OLTP composite tenant keys.

## 3. Dimensions (`DIM-*`)

Conformed dimensions — shared by every fact table so all facts slice the same way.

| DIM id | Source entity / concept | Hierarchy / key attributes |
|---|---|---|
| **DIM-Date** | derived calendar | day → **week → month → quarter → year** (the time grains) |
| **DIM-Creator** | [`ENT-Creator`](00-data-model.md#ent-creator) | creator, tier/size band |
| **DIM-Platform** | [`ENUM-Platform`](../00-meta/03-glossary.md#enum-platform) | INSTAGRAM / TIKTOK / YOUTUBE |
| **DIM-ContentType** | [`ENUM-ContentType`](../00-meta/03-glossary.md#enum-contenttype) | post / reel / video / short / … |
| **DIM-Client** | [`ENT-Client`](00-data-model.md#ent-client) | client |
| **DIM-Brand** | [`ENT-Brand`](00-data-model.md#ent-brand) | client → **brand** |
| **DIM-Product** | [`ENT-Product`](00-data-model.md#ent-product) | brand → **product** (SKU / variant) |
| **DIM-Campaign** | [`ENT-Campaign`](00-data-model.md#ent-campaign) | campaign |
| **DIM-SeedingCampaign** | [`ENT-SeedingCampaign`](00-data-model.md#ent-seedingcampaign) | seeding program |
| **DIM-Geo** | [`ENT-GeoAttribution`](00-data-model.md#ent-geoattribution) | **city → region → country** (carries its `ConfidenceAssessment`) |
| **DIM-MentionType** | [`ENUM-MentionType`](../00-meta/03-glossary.md#enum-mentiontype) | paid / seeded / likely-organic / unknown |
| **DIM-Sector** | [`ENUM-SectorLabel`](../00-meta/03-glossary.md#enum-sectorlabel) | content/brand sector |
| **DIM-Sentiment** | [`ENUM-SentimentLabel`](../00-meta/03-glossary.md#enum-sentimentlabel) | positive / neutral / negative / … |
| **DIM-MetricTier** | [`ENUM-MetricTier`](../00-meta/03-glossary.md#enum-metrictier) | PUBLIC / DERIVED / ESTIMATED / CONFIRMED — never blend across a fact total |

> `DIM-Geo` is a **confidence-based** dimension: a city/country is an inferred attribution ([DP-003](../20-cross-cutting/00-data-principles.md#dp-003)), so geo-sliced aggregates inherit that uncertainty and must be labelled as inferred, never asserted as fact.

---

## 4. Fact tables (`FACT-*`)

Each declares its **grain** (what one row is). All measures carry a tier.

### FACT-ContentMetric
- **Grain:** one row per `ContentItem` **per metric snapshot bucket** (from [`ENT-MetricSnapshot`](00-data-model.md#ent-metricsnapshot)). Append-only time-series — this is how "how much, when" is preserved.
- **Measures:** `views`, `plays`, `likes`, `comments`, `shares`, `saves` (all `PUBLIC`, additive).
- **Dimensions:** DIM-Date, DIM-Creator, DIM-Platform, DIM-ContentType, DIM-Brand (if the content carries a mention), DIM-Geo.

### FACT-CreatorAccount
- **Grain:** one row per tracked [`PlatformAccount`](00-data-model.md#ent-platformaccount) **per snapshot bucket** (account-level [`ENT-MetricSnapshot`](00-data-model.md#ent-metricsnapshot)). This is the **overall influencer-account** time-series that powers roster/account monitoring.
- **Measures:** `followers`, `following`, `total_posts` (`PUBLIC` snapshot values); `follower_growth`, `avg_views`, `engagement_rate`, `posting_frequency` (`DERIVED` — recomputed, never summed).
- **Dimensions:** DIM-Date, DIM-Creator, DIM-Platform, DIM-Geo, DIM-Sector.

### FACT-Mention
- **Grain:** one row per detected [`ENT-Mention`](00-data-model.md#ent-mention), stamped with the linked content's latest metrics.
- **Measures:** `mention_count`=1 (additive), `estimated_reach` (`ESTIMATED`), `emv` (`ESTIMATED`), `engagement` components (`PUBLIC`).
- **Dimensions:** DIM-Date, DIM-Creator, DIM-Brand, DIM-Product, DIM-Campaign, DIM-Platform, DIM-ContentType, DIM-MentionType, DIM-Sentiment, DIM-Geo, DIM-MetricTier.
- **Stamping** *(amended 2026-07-07 — as-built reconciliation)*: stamped at load with the then-latest joined metrics/sentiment/EMV; because campaign attribution lands **after** detection, an already-loaded mention whose source row mutates is **re-stamped** through the gated restamp (§2, principle 3) so late `campaignId`s reach `ROLLUP-MentionByCampaign`.

### FACT-Shipment
- **Grain:** one row per [`ENT-Shipment`](00-data-model.md#ent-shipment) (the seeding event).
- **Measures:** `shipped`=1, `posted` (0/1), `quantity`, `product_value` (from `productValueAtShip`), `days_to_post` (postedAt − shippedAt). Counts additive; `days_to_post` averaged (non-additive).
- **Dimensions:** DIM-Date (shippedAt), DIM-Creator, DIM-Product, DIM-Brand, DIM-Client, DIM-SeedingCampaign, DIM-Campaign.
- **Stamping** *(amended 2026-07-07 — as-built reconciliation)*: `posted` and `days_to_post` change on the *existing* grain (one row per shipment), so mutated shipments are **re-stamped** through the gated restamp (§2, principle 3) — never updated in place. A `postedAt` that predates `shippedAt` (operator links pre-existing content) loads `days_to_post` as `NULL`, never a negative that poisons averages.

### FACT-SeedingContent
- **Grain:** one row per (`Shipment` × resulting `ContentItem`) **per snapshot bucket** — the content a seeded creator posted, matched to the shipment via [REQ-M3-008](../90-traceability/00-req-matrix.md), tracked over time.
- **Measures:** `content_count`=1, `views`, `likes`, `comments`, `shares`, `saves` (`PUBLIC`), `estimated_reach` (`ESTIMATED`), `emv` (`ESTIMATED`). Additive within a tier.
- **Dimensions:** DIM-Date (postedAt), DIM-Creator, DIM-Product, DIM-Brand, DIM-Client, DIM-SeedingCampaign, DIM-Campaign, DIM-Platform, DIM-ContentType, DIM-Geo, DIM-MetricTier.

> **The seeding chain that makes it all join:** `Product` → `Shipment` (to a `Creator`) → matched `ContentItem`(s) → `MetricSnapshot`s. `FACT-Shipment` answers *shipped/posted/when*; `FACT-SeedingContent` answers *how much reach/views/engagement*; both key on `DIM-Product`, so a product seeded to many creators rolls up to one total.

---

## 5. Rollups (`ROLLUP-*`) — scheduled materialized views

Dashboards and exports read **only** these. Each is a **materialized view / rollup table** refreshed on a schedule (e.g. every 15–60 min) by `SVC-Analytics`, keyed by a time grain plus dimensions. "Current" rollups use the **latest** snapshot per content; "trend" rollups keep every bucket for over-time charts (follower growth, before/after).

| ROLLUP id | Grain | Key measures | Serves |
|---|---|---|---|
| **ROLLUP-SeedingByShipment** | per shipment | shipped, posted, days_to_post, content_count, views, estimated_reach, likes, comments, shares, saves, emv | Per-influencer seeding tracking: *what did we send, did they post, when, how did it perform* |
| **ROLLUP-SeedingByCreatorCampaign** | creator × seeding campaign | posted, first_posted_at, content_count, views, estimated_reach, engagement, emv | Per-creator results inside a seeding program |
| **ROLLUP-SeedingByProduct** | **product** (all creators) × {week, month, quarter, year} | shipments, posted_count, **post_rate**, creators_reached, content_count, **total_views**, **total_estimated_reach**, total_engagement, **total_emv** | **Product seeded to N influencers → one total** (reach / views / EMV) |
| **ROLLUP-SeedingByBrand** | brand × period | shipments, posted_count, content_count, total_views, total_estimated_reach, total_emv | Brand-level seeding performance |
| **ROLLUP-MentionByBrand** | brand × period | mention_count, total_views, total_estimated_reach, total_emv, **share_of_voice** | Brand monitoring dashboards |
| **ROLLUP-MetricByGeo** | country/city × platform × period | content_count, views, estimated_reach, emv | Country/city distribution |
| **ROLLUP-CreatorByPeriod** | creator × period | followers, **follower_growth**, avg_views (DERIVED), engagement_rate (DERIVED), posting_frequency, last_post_at | **Overall influencer-account monitoring** (Module 1) + creator trends/growth (Module 2) |
| **ROLLUP-MentionByCampaign** *(additive — amended 2026-07-07)* | campaign × {week, month, quarter, year} | mention_count, content_count, total_views, total_likes, total_comments, total_engagement, total_estimated_reach, total_emv | Campaign-level mention results — the campaign half of [REQ-M3-009](../90-traceability/00-req-matrix.md) |
| **ROLLUP-SeedingByProductSlice** *(additive — amended 2026-07-07)* | product × platform × content type × country × {week, month, quarter, year} | creators_reached (**posters in the slice**), content_count, total_views, total_engagement, total_estimated_reach, total_emv | [REQ-M3-013](../90-traceability/00-req-matrix.md) slicing by platform / content type / country |

> **Amended 2026-07-07 — as-built reconciliation (additive; the seven rollups above are unchanged):**
>
> - **ROLLUP-MentionByCampaign** mirrors `ROLLUP-MentionByBrand` (grain × bucket, non-null grouping key) but groups `FACT-Mention` by campaign — rows with no campaign attribution are excluded until the gated restamp (§2, principle 3) brings the late `campaignId` in — and adds the results measures the campaign panel needs (content_count, likes/comments, and the engagement-component sum).
> - **ROLLUP-SeedingByProductSlice** re-groups `ROLLUP-SeedingByProduct`'s **content-side measures only** by platform / content type / country (the [REQ-M3-013](../90-traceability/00-req-matrix.md) slices). Shipments carry no platform, so shipment counts and `post_rate` stay on the unsliced `ROLLUP-SeedingByProduct` and consumers combine the two views. `creators_reached` in a slice therefore counts creators who **posted** within the slice (from `FACT-SeedingContent`), *not* creators shipped to. Content is reduced to the latest snapshot and one row per content item per product bucket, so multi-shipment links don't double-count. `country` comes from the single **highest-confidence** `DIM-Geo` attribution per creator (avoiding measure fan-out); `DIM-Geo` is empty until Module 2 ships `ENT-GeoAttribution`, so `country` is `NULL` and country slices render "unavailable" — never zero ([deferred register](../20-cross-cutting/01-deferred-register.md)).
> - In both, an engagement sum is `NULL` when **no** component was observed for any row in the bucket — a fabricated zero would present absence as fact ([DP-001](../20-cross-cutting/00-data-principles.md#dp-001)); partially observed rows sum their observed components. `total_estimated_reach` / `total_emv` carry the same fixed `ESTIMATED` tier-label columns as the canonical rollups.

Rules that keep these honest:
- `total_estimated_reach` and `total_emv` are **`ESTIMATED`** aggregates — every surface labels them as estimates ([DP-001](../20-cross-cutting/00-data-principles.md#dp-001)); confirmed reach/impressions is deferred ([DEF-003](../20-cross-cutting/01-deferred-register.md#def-003)), so "total impressions" in v1 means labelled estimated reach.
- `post_rate`, `engagement_rate`, `avg_views` are **`DERIVED`** — recomputed from summed components at the rollup grain, never summed directly.

---

## 6. Worked example — the seeding scenario end-to-end

**Setup.** Brand *Aurora* seeds **Product `SERUM-30ML`** to 12 creators.

1. **Ship & track (per influencer).** Each `Shipment` records `productId=SERUM-30ML`, `shippedAt`, `deliveredAt`. → `FACT-Shipment` (12 rows: shipped=1).
2. **Did they post? when?** Content matching ([REQ-M3-008](../90-traceability/00-req-matrix.md)) links a creator's reel to the shipment and sets `posted=true`, `postedAt`, `resultingContentIds`. → `FACT-Shipment.posted` flips; `days_to_post` computed *(as-built, the flip is realized by the gated re-stamp — §2, principle 3 — not an in-place update)*.
3. **How much? (over time).** Each resulting `ContentItem` accrues `MetricSnapshot`s → `FACT-SeedingContent` rows per bucket (views/reach/likes/EMV as they grow).
4. **Per-influencer view.** `ROLLUP-SeedingByShipment` shows, for creator A: shipped 12 Jun, posted 18 Jun (6 days), 1 reel, 84k views, ~55k estimated reach, 4.1k likes, €1.2k EMV.
5. **Product total across all 12 creators.** `ROLLUP-SeedingByProduct` for `SERUM-30ML` this quarter: 12 shipments, 9 posted (**75% post-rate**), 11 content items, **total views 940k**, **total estimated reach ~610k** *(labelled estimate)*, **total EMV €14.8k** *(labelled estimate)*.

That is exactly "track the product seeded to multiple influencers → total reach / views / impressions / EMV", with per-influencer detail underneath and full over-time history.

---

## 7. Scalability

- **Native declarative partitioning** splits facts by time → pruned scans and cheap partition drop as data grows into tens/hundreds of millions of rows.
- **Scheduled rollup refresh** (materialized views, or incremental upserts into rollup tables) keeps aggregates warm, so a year-over-year query touches small pre-aggregated buckets, not raw facts.
- **Retention:** detach/drop fact partitions older than a threshold (native partitioning); keep raw facts for a bounded window (e.g. 90–180 days) and keep rollups indefinitely. Retention policy is a `SVC-Analytics` concern and must honour GDPR retention ([DP-005](../20-cross-cutting/00-data-principles.md#dp-005)).
- **Columnar escape hatch:** at very large scale, stream `FACT-*` to **ClickHouse** (or a warehouse) via CDC; the star schema is engine-portable ([ADR-0010](../05-decisions/decision-log.md#adr-0010)).

---

## 8. Consumers

`SVC-Analytics` maintains all `FACT-*` and `ROLLUP-*`; `SVC-Export` and the dashboards read rollups only (see [system architecture](../60-architecture/00-system-architecture.md)). Requirement coverage for the seeding aggregation is [REQ-M3-009](../90-traceability/00-req-matrix.md) (results) and [REQ-M3-013](../90-traceability/00-req-matrix.md) (product-level cross-influencer aggregation). Overall influencer-account monitoring is [REQ-M1-005](../90-traceability/00-req-matrix.md) and [REQ-M1-007](../90-traceability/00-req-matrix.md), served by `FACT-CreatorAccount` → `ROLLUP-CreatorByPeriod`.
