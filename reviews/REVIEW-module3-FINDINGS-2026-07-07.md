<!--
  Consolidated deep-review FINDINGS — Module 3 (CRM & Seeding), Steps 1-4.
  Produced by the deferred adversarial (Ultracode) review the product owner
  requested be run after implementation. This is the independent review the
  four reviews/REVIEW-module3-*.md handoffs were waiting on.
-->

# Module 3 Deep Review — Consolidated Findings

- **review_status:** REVIEWED
- **outcome:** ACCEPT WITH FIXES — two findings (C1, C2) block trustworthy campaign/EMV reporting and must be fixed before those numbers are shown; nothing is a security breach.
- **reviewed_by:** adversarial multi-agent review (87 agents: 13 dimension×area finders → 3 independent refutation skeptics per finding → synthesis + completeness critic), orchestrated 2026-07-07.
- **scope:** the entire uncommitted Module 3 diff, Steps 1-4 (127 changed files).
- **method:** each finding survived only if ≥2 of 3 independent skeptics (reachability / evidence / intended-behaviour lenses) voted it REAL. 24 raised → **18 confirmed** (deduped to 14) → 6 refuted. Read-only; no code changed.

> This file is the review record for all four steps. The per-step handoffs
> (reviews/REVIEW-module3-{data-foundation,identity-merge,campaigns-seeding,results-docs-tasks}-*.md)
> are marked REVIEWED and point here.

---

## Executive verdict

**Acceptable only with fixes — two findings block client-facing use.** Module 3's code is broadly sound, but the seeding→campaign attribution path that feeds the flagship REQ-M3-009 results has two silent, default-path data-integrity defects that make campaign mention analytics untrustworthy today.

The single most dangerous finding is **C1**: `ContentMatchFeedbackRecorder::confirm()` blanket-stamps a campaign onto *every* unattributed mention on a content item, so a multi-brand post silently attributes Brand B's mention to Brand A's campaign — affirmatively false, cross-brand data written on evidence that never supported it, no error surfaced. (The completeness critic independently rediscovered this as GAP-1 — strong corroboration.) Close behind is **C2**: the stamp-once `fact_mention` loader permanently excludes essentially every late-attributed mention from `rollup_mention_by_campaign`, systematically undercounting the campaign panel on the common path. Both corrupt the headline deliverable by default and violate the DP-001 honesty contract.

Nothing is a security breach; authz across the ~15 new panels is consistent, the restamp GUC gate is correctly transaction-local, and the document upload/download path is signed+authorized.

**Disposition — ALL FINDINGS RESOLVED (batches 1+2, 2026-07-07):**
- **Blockers:** C1, C2 — **FIXED (batch 1)**.
- **Trust-critical:** H1, M1–M3 — **FIXED (batch 1)**; M4 — **FIXED (batch 2)**.
- **Docs/hardening:** M5, M6, L1–L3, GAP-2/3/4, L5 — **FIXED (batch 2)**; L4 — gate-isolation half covered by a batch-1 test, the down()-restores-P0 assertion remains untested (accepted residual). GAP-1 = C1.
- **Residual candidates for a future pass:** ReportBuilder::emvDisclosure still uses its pre-existing P1 active-config precedent (the M4 fix scoped the three results surfaces); AC-M3-017's reminder *channel* stays canon-silent (in-app only — ADR candidate).

---

## Fix record — batch 1 (2026-07-07, inline by the orchestrator; full suite 616 passed / 2,546 assertions, PHPStan 6 zero errors, Pint clean)

- **C1 FIXED — evidence-scoped XMC-002 confirm.** Design note: the review's suggested "scope by the subject's brand" was unimplementable — `MonitoredSubject` is creator-typed (ADR-0011) and `Mention` carries no brand column (fact-side brand derives from the attributed campaign). The correct scope is the **evidencing mention itself**: `ContentMatchFeedback::confirm(content, campaignId, shipmentIds)` now stamps only mentions whose classification signals reference one of the evidencing shipments (`ShipmentEvidence::shipmentIdFrom`), falling back to the content's SINGLE unattributed mention (no ambiguity), else stamping nothing — never guessing among several. Callers updated: `SeededContentLinker` passes the processed mention's own references; `ShipmentsPanel::linkContent` passes the linked shipment. Tests: two-brand post scenario (stamp one, sibling stays null, sibling later attributable to ITS campaign), single-mention fallback, ambiguity-stamps-nothing, never-clobber. `deny()` was already correctly campaign-scoped — unchanged.
- **M3 FIXED** — `ShipmentsPanel::unlink()` now retracts the attribution only when **no surviving shipment link** resolves the content to the same parent campaign (`survivingLinkFor()`); test covers unlink-one-of-two (survives) then unlink-last (retracts).
- **C2 FIXED** — `fact_mention` gained the same gated restamp as `fact_shipment`, behind its **own** transaction-local GUC `qds.analytics_mention_restamp` (per-table gates; one never loosens the other): µs-epoch watermark `fact_mention_restamp` on `mentions.updated_at` (inclusive `>=`), DELETE + fresh re-INSERT of mutated mentions inside the refresh transaction; late attributions now reach `rollup_mention_by_campaign` (and fill `brand_id`). First run after the fix re-stamps all previously loaded mentions once (heals pre-fix staleness). Tests: NULL-load → attribute → refresh → in-rollup; per-table gate isolation both directions.
- **H1 FIXED** — the `CASE WHEN all-four-components IS NULL THEN NULL` guard from `rollup_mention_by_campaign` applied to every seeding engagement expression (`rollup_seeding_by_creator_campaign`, `rollup_seeding_by_product`, `rollup_seeding_by_product_slice`); unobserved engagement now reads NULL → "unavailable", never 0. Test: views-only snapshot yields NULL engagement in all three.
- **M2 FIXED** — content-side aggregation in `rollup_seeding_by_product`, `rollup_seeding_by_creator_campaign`, `rollup_seeding_by_product_slice` **and `rollup_seeding_by_brand`** (same defect class, fixed for consistency) now dedupes to one row per content item per group/bucket before summing; a reel linked to two shipments of one product counts once. Test: two-shipment link → content_count 1, views/engagement not doubled, across all four views. Note: the seeding views live in the P0-dated migration `2026_07_05_200003` — edited in place (test DB rebuilds from migrations), flagged.
- **M1 FIXED** — `SeedingCampaignsIndex::save()` enforces parent-campaign↔brand coherence (mirrors the product guard, covers create AND edit); the campaign dropdown is now scoped to the selected brand. Test: cross-brand parent rejected on create and edit; same-brand still saves.
- **Independent adversarial verification of this batch: PENDING** (the verify agents died on a session limit; re-run when capacity allows).

## Fix record — batch 2 (2026-07-07, inline; full suite 618 passed / 2,555 assertions, PHPStan 6 zero errors, Pint clean)

- **M4 FIXED** — new `EmvCalculator::producingConfigurations()` (distinct models behind the latest `emv_results` row per content — the exact population the fact loaders stamp EMV from) + shared `<x-metric.emv-disclosure>` component replacing the three inline blocks. Disclosure now survives a rate-card activation without recalculation (figure produced under v1 keeps disclosing v1; both models + a "span N rate cards" caveat once v2 actually produces figures; "no EMV has been computed" when none exist). Tests rewritten to pin the producing-vs-active distinction. *Residual:* `ReportBuilder::emvDisclosure` keeps its pre-existing P1 precedent.
- **M5 FIXED** — ADR-0016 scope notes added at vision-and-scope (×2), system-architecture (L0 diagram blockquote + L6 delivery line), module-1 access note.
- **M6 FIXED** — ADR-0016 Decision + Consequences corrected to describe the shipped code: the P0 containment shell (`/reports` route rendering a permanent empty state, the CLIENT_VIEWER login redirect, the seeded `reports.view-approved` grant) **remains wired** and exposes no data. Chose correcting the ADR over neutralizing the code: the shell is reviewed P0 behavior pinned by P0 tests, and removal would gain nothing (empty stub).
- **L1 FIXED** — migration `2026_07_07_100001` adds partial unique index `platform_accounts (creator_id, platform) WHERE creator_id IS NOT NULL`; `CreatorWriter` translates the index violation into `PlatformAccountConflict::platformTaken` on both the add and update paths; DB-backstop test added. One test fixture (SeededContentLinkerTest) had violated the real invariant — corrected to a second platform.
- **L2 FIXED** — `file_name` removed from `document.uploaded/deleted/downloaded` audit contexts (ids only; the GDPR-erasure test now asserts NO file_name key).
- **L3 FIXED** — `document.deleted` audit write moved inside the delete transaction, after row + blob removal.
- **GAP-2 FIXED** — `SeededContentLinker::run(?since)` + `qds.matching.lookback_hours` (48) window on scheduled passes; `qds:link-seeded-content --all` forces the historical full rescan. Hourly cost now bounded by the window, not total history.
- **GAP-3 FIXED** — reminder stamp + audit are one atomic transaction; "Reminded" badge added to the tasks panel (the tasks index already displayed "Reminder fired …" — the critic's "inert" claim was half-stale). The reminder *channel* (in-app only) remains a flagged canon-silent decision.
- **GAP-4 FIXED** — `days_to_post` loads NULL when `posted_at` predates `shipped_at` (manually linked pre-existing content); test added.
- **L5 FIXED** — engagement KPI pinned via delimited `assertSeeHtml('>50</span>')`.
- ~~Independent adversarial verification of batches 1+2: PENDING~~ — **RUN 2026-07-07 (5 read-only verifiers, see below).**

## Verification record — independent adversarial pass over batches 1+2 (2026-07-07)

Five parallel read-only verifiers (attribution / analytics-core / coherence-backstop / EMV-docs / hardening), each instructed to refute the fixes. **Verdict: 13 of 14 fixes confirmed at the root; the first C1 fix was REFUTED and has been repaired (C1-bis, below); all actionable verifier issues were addressed the same day.** Final bar after repairs: my affected suites green (31 tests around the attribution/reminder/documents fixes; 101 across all touched areas), PHPStan 6 zero errors, Pint clean on all files of this effort. (Full-suite runs now include a CONCURRENT session's in-flight ingestion/roster work in the same tree — last two full runs were green at 634/638 tests; transient mid-run failures were cross-session test-DB races, not defects.)

**C1-bis — the refuted fallback, repaired.** Verifier A proved the single-null-candidate fallback re-created the cross-brand mis-stamp one linker pass later: run 1 stamps the evidenced mention (bumping updated_at back into the lookback window); run 2 re-fires confirm; candidates shrink to the OTHER brand's lone null sibling; count===1 → fallback stamps it. Also reachable manually via a second shipment link. **Fix:** the fallback now additionally requires the content to carry a single mention IN TOTAL (`$content->mentions()->count() === 1`) — multi-mention posts never guess. Also fixed the verifier's minor: the linker now passes only shipments that actually LINKED to confirm() (a stale/foreign reference can no longer vouch for a sibling). **Pinned at two levels:** recorder test (attributed-evidenced + null sibling → nothing stamped) and end-to-end linker double-run test (sibling stays null forever).

**Other verifier issues addressed:**
- **M2 tiebreak (B):** the four dedupe row_numbers gained a deterministic `, c.id DESC` tiebreak (snapshot-id ties on the exact M2 input no longer flip emv/reach between refreshes).
- **GAP-2 edge regression (E):** eligibility can change without bumping mentions.updated_at (e.g. a parent campaign attached to a run later) — a daily `qds:link-seeded-content --all` full rescan is now scheduled (04:30) alongside the hourly windowed pass, healing aged-out state within 24h.
- **Unpinned fixes now pinned (E):** GAP-2 window + --all (2 new tests), GAP-3 reminder visibility on index + panel (1 new test), L2 uploaded/downloaded audit contexts (2 new assertions).
- **Copy fixes (D):** reports/index.blade.php empty-state no longer promises a future reports surface ADR-0016 voids; the multi-rate-card EMV disclosure line now carries a "workspace-wide disclosure" scope qualifier.

**Verifier-accepted residuals (recorded, not fixed):**
- C2: READ-COMMITTED race window in the restamp (self-healing next run) and clock-skew watermark overshoot — both inherited from the review-accepted fact_shipment pattern, consistent by design.
- M2: same-content-per-BUCKET dedupe means divergent date_keys (only reachable for published_at-NULL content with skewed posted_at fallbacks) can still count once per bucket — date-attribution ambiguity, run totals unaffected.
- M4: producingConfigurations() is workspace-global (direction-safe superset per panel, wording now says so); disclosure reads live emv_results while figures come from the last refresh (staleness window); O(emv_results) window query per panel render — optimize if it ever shows.
- L1: the QueryException→PlatformAccountConflict translation layer is dead code under single-threaded tests (untestable without concurrency); the DB index itself is pinned.
- L3 inverse hazard: blob deleted + row-delete rolled back if the audit INSERT fails (low probability; pre-fix ordering could not produce it) — accepted.
- L4 residual: down()-restores-P0 verified by reading (byte-identical), still untested; cross-gate test covers fact_shipment↔fact_mention only.
- Same-class L2 candidates outside the reviewed scope: task.deleted audits the user-entered title; clients/brands audit names — future hygiene pass.
- Migration-in-place notes: 110002's filename still says shipment-only though it now carries both gates; dev DBs that ran pre-fix versions of edited migrations need a rebuild (loud failure, not silent corruption; nothing committed to git).
- module-1-monitoring.md ~:355 white-label line unqualified (one indirection from the qualified roadmap); the system-architecture blockquote's "ships disabled" wording corrected by its own next clause.

---

## CRITICAL

### C1 — `confirm()` blanket-stamps a campaign onto sibling mentions (cross-brand / cross-campaign mis-attribution) — NEW
`app/Modules/Monitoring/Services/ContentMatchFeedbackRecorder.php:25-35`
**Defect:** `confirm(content, campaignId)` sets `campaign_id` on `$content->mentions()->whereNull('campaign_id')` — *every* unattributed mention on the content, with no scoping to the mention's `monitored_subject_id`/brand or the shipment that justified the campaign. `mentions.campaign_id` is per-mention (one brand's appearance), so the write leaks the campaign across brand/campaign boundaries.
**Failure scenario:** One post tags Brand A (SEEDED → campaign X) and Brand B (organic or campaign Y) — two null-campaign mentions on one content. The linker processes the Brand A mention and calls `confirm(content, X)`, stamping `campaign_id=X` on the Brand B mention too. When the Brand B mention is later processed, the `whereNull` guard makes it a no-op, so Brand B is permanently attributed to campaign X — inflating X's content-count/views/EMV and corrupting Brand B's analytics. **Default outcome for any multi-brand or two-campaign post, not a rare race.**
**Fix:** Pass the matched `Mention` (or its `monitored_subject`/brand) through the XMC-002 contract and update only the mention(s) whose subject corresponds to the campaign's brand — never all null-campaign mentions on the content.

## C2 — Stamp-once `fact_mention` + late attribution systematically empties `rollup_mention_by_campaign` (REQ-M3-009); no test pins it — DISCLOSED
`app/Platform/Analytics/NeonAnalyticsService.php:351-358` (+ `database/migrations/analytics/2026_07_06_110001.php:62-64`; test gap at `tests/Feature/Analytics/SeedingAnalyticsTest.php:296-351`)
**Defect:** `loadMentionFacts()` is append-only on an id watermark and stamps `campaign_id`/`brand_id` once at insert, with no restamp path (unlike `fact_shipment`). But the only writer of `mentions.campaign_id` is XMC-002 `confirm()`, which sets it *after* mentions are created and fact-loaded. `rollup_mention_by_campaign` filters `WHERE f.campaign_id IS NOT NULL`, so any mention fact-loaded while NULL is permanently excluded.
**Failure scenario:** Mention #500 detected 09:00 (campaign_id NULL). Refresh 09:30 inserts `fact_mention(500, NULL)`, advances watermark past 500. 10:00 the linker sets `mentions.campaign_id=77`. Every later refresh sees `id ≤ watermark` and never re-scans, so `fact_mention.campaign_id` stays NULL forever; campaign 77's panel understates mention_count/views/engagement/EMV permanently. Because attribution always post-dates detection, this is the default for essentially every campaign-attributed mention.
**Fix:** Extend the gated DELETE+re-INSERT restamp already built for `fact_shipment` to `fact_mention` keyed on `mentions.updated_at` (or reconcile `campaign_id`/`brand_id` post-attribution), and add a test: load a NULL mention → attribute → refresh → assert it appears in the rollup.

---

## HIGH

### H1 — Seeding engagement fabricates a zero when no component was observed (DP-001; same-page self-contradiction) — DISCLOSED
`database/migrations/analytics/2026_07_05_200003_create_analytics_rollups.php:244` (also `:308`; `2026_07_06_110001:115`)
**Defect:** The three seeding rollups compute engagement as `sum(coalesce(likes,0)+…+coalesce(saves,0))` with no null-guard, unlike `rollup_mention_by_campaign` which wraps the same sum in a `CASE` returning NULL when all four are unobserved. A run with content but no observed engagement component gets `total_engagement = 0` (non-null).
**Failure scenario:** A creator posts one Story recording views but no likes/comments/shares/saves. The run-total Engagement KPI renders "0 DERIVED" (a fabricated zero on a client-facing figure), while that same creator's per-creator row on the *identical panel* correctly renders "unavailable" (it goes through `SeedingResultsPanel::nullableSum()`). `/crm/results` also shows "0 DERIVED" for the product bucket.
**Fix:** Apply the `CASE WHEN likes IS NULL AND comments IS NULL AND shares IS NULL AND saves IS NULL THEN NULL ELSE coalesce-sum END` guard to all three seeding engagement expressions.

---

## MEDIUM

### M1 — Seeding run linkable to a different-brand parent Campaign → cross-brand attribution — NEW
`app/Modules/CRM/Livewire/Seeding/SeedingCampaignsIndex.php:159-173`
`save()` enforces product↔brand coherence but no coherence between `seeding_brand_id` and the optional `seeding_campaign_id`; the dropdown (line 290) lists all campaigns unfiltered by brand. A `SeedingCampaign(brand=Acme)` linked to a Rival-owned Campaign attributes Acme's seeded content to Rival's campaign. **Fix:** if `seeding_campaign_id` set, verify `Campaign::find(id)->brand_id === seeding_brand_id` and throw otherwise, mirroring the product-brand guard.

### M2 — Cross-shipment link double-counts content/views/EMV in the product total (AC-M3-019) — DISCLOSED
`database/migrations/analytics/2026_07_05_200003_create_analytics_rollups.php:302-315` (also `:231-250`)
`rollup_seeding_by_product`/`_by_creator_campaign` reduce with `row_number()` partitioned by `(shipment_id, content_item_id)`, but `shipment_resulting_content` is many-to-many. One content linked to two shipments of the same product yields two `rn=1` rows, each metric summed once per link → `/crm/results` shows 2× views/EMV. **Fix:** dedupe to one row per `content_item_id` per group (count/sum DISTINCT on `content_item_id`).

### M3 — Unlinking one shipment retracts attribution a surviving link still justifies — NEW
`app/Modules/CRM/Livewire/Seeding/ShipmentsPanel.php:295-314`
`unlink()` detaches one pivot row then unconditionally calls `feedback->deny(content, campaign_id)`, without checking whether the content is still linked to another shipment in the same campaign. Removing S1's link nulls K's `campaign_id` even though S2 still validly links K to that campaign; if S2's link was manual, the loss is permanent. **Fix:** only `deny` when zero remaining shipments resolve the content to that campaign.

### M4 — EMV disclosure shows the *currently-active* config, not the model/rates that produced the figure (GL-EMV / AC-M1-011) — NEW
`campaign-results.blade.php:88-121` (+ `CampaignResultsPanel.php:77`); `SeedingResultsPanel.php:136` (+ `seeding-results.blade.php:105-110`); `SeedingResultsDashboard.php:141`
`total_emv` sums immutable `emv_results` rows (each snapshotting its own formula/rate-card/rates), but the blades disclose `EmvCalculator::activeConfiguration()` — the config active *now*. Activating a new rate card never re-stamps existing facts, so the disclosed rates diverge from the figure; deactivating all configs renders a monetary EMV with no provenance at all. **Fix:** carry formula/rate-card/currency/rates from the `emv_results` feeding the rollup and disclose those (or render EMV "unavailable" when the disclosing config doesn't match the producing one).

### M5 — ADR-0016 not propagated: top-tier canon still asserts CLIENT_VIEWER external report capability — DISCLOSED
`docs/10-product/00-vision-and-scope.md:21, 77`; `docs/60-architecture/00-system-architecture.md:61, 186`; `docs/50-modules/module-1-monitoring.md:359-360`
The vision primer still states as present fact "the only externally-facing surface is read-only, approved reporting for the agency's own clients"; system-architecture's actor diagram + delivery layer + module-1 assert CLIENT_VIEWER report retrieval as a live capability — directly contradicting the ADR-0016 added this diff. Two APPROVED same-tier docs now disagree on "does v1 have external client access?" **Fix:** add the ADR-0016 v1-scope qualifier to all five lines. *Docs-only; code correctly enforces deny-everything.*

### M6 — ADR-0016 misstates shipped code: the `/reports` surface and CLIENT_VIEWER login redirect are actually wired — NEW
`docs/05-decisions/decision-log.md:393-399` (contradicted by `routes/web.php:20-22`, `app/Shared/Http/Responses/LoginResponse.php:21`, `PermissionsCatalog.php:136`, `RolePermissionSeeder.php:25`)
ADR-0016 asserts v1 "ships no approved-report surface" and `reports.view-approved` is "granted to no surface." In fact a live `/reports` route gated by `can:reports.view-approved` renders `reports.index`, `LoginResponse` redirects `isClientViewer()` logins to it, and the permission is already **seeded to CLIENT_VIEWER**. (One skeptic refuted the *data-exposure* impact — `reports.index` is currently an empty stub — but the doc-vs-code contradiction and the latent authz posture stand.) **Fix:** correct ADR-0016 to describe the actual wiring, or neutralize the `/reports` route + view + redirect and remove the CLIENT_VIEWER grant so "no surface ships" is literally true.

---

## LOW

### L1 — One-account-per-platform-per-creator enforced only in app code; no DB backstop for the race — DISCLOSED
`app/Modules/CRM/Services/CreatorWriter.php:220-231`
`assertPlatformFree()` is a lock-free SELECT-then-INSERT; the only relevant unique key is `(platform, handle)`. Two overlapping adds of INSTAGRAM `@alpha` and INSTAGRAM `@beta` to creator #7 both pass and both insert (handles differ), leaving two same-platform accounts — canon-forbidden, no error. **Fix:** partial unique index `UNIQUE(creator_id, platform) WHERE creator_id IS NOT NULL`, translate `QueryException` → `PlatformAccountConflict::platformTaken`.

### L2 — Client-supplied document filename written into the append-only audit log, defeating GDPR erasability — DISCLOSED
`app/Modules/CRM/Livewire/Documents/DocumentsPanel.php:116-121, 154-159`
`document.uploaded/deleted/downloaded` record raw `getClientOriginalName()`, contradicting AuditLogger's own identifiers-only rule and the sibling `ContactsPanel::delete` (records no context). `Marie_Lefebvre_influencer_contract_2026.pdf` persists in three immutable rows after a GDPR erasure. **Fix:** drop `file_name` from document audit contexts (`subject_id` already identifies the row).

### L3 — `document.deleted` audit recorded before the delete transaction that can roll back — NEW
`app/Modules/CRM/Livewire/Documents/DocumentsPanel.php:154-172`
The audit write at line 154 auto-commits before the `DB::transaction()` at 164, which rolls back when the blob delete fails while the blob still exists — an immutable entry asserts a deletion that did not occur while the row stays downloadable. **Fix:** move the audit write inside the transaction, after row+blob removal succeed.

### L4 — Restamp GUC gate never tested to leave the *other* fact tables append-only — DISCLOSED
`tests/Feature/Analytics/SeedingAnalyticsTest.php:274-294`
The suite asserts `fact_shipment` UPDATE and gate-OFF DELETE are refused, but never sets the GUC on and asserts DELETE/UPDATE on `fact_mention`/`fact_seeding_content` still raise. A future broadening of the trigger predicate could corrupt non-shipment facts with the suite still green. Current code is correct. **Fix:** add the GUC-on regression test + a `down()`-restores-P0 assertion.

### L5 — Vacuous engagement-total assertion (weak; likely overturnable) — NEW
`tests/Feature/Crm/CampaignResultsPanelTest.php:163`
`assertSee('50')` is satisfied by the views value `'500'` (substring). The engagement cell isn't pinned by *this* line — but the same file's CPE/CPM test (`'10.00'` = 500/50) and the DERIVED badge do catch a wrong engagement value, so it's a test-quality nit, not a real coverage gap. **Fix (optional):** assert a labelled `Engagement 50` fragment.

---

## Completeness critic — gaps the finder pass may have left (follow-up leads)

- **GAP-1 (High)** — same defect as **C1**, independently rediscovered (`ContentMatchFeedbackRecorder.php:25`). Corroborates the Critical rating.
- **GAP-2 (Medium, scale)** — `SeededContentLinker::run()` (`app/Platform/Enrichment/Matching/SeededContentLinker.php:45-49`) has no incremental watermark: every hourly run re-walks *all* historical SEEDED mentions and re-`link()`s already-linked ones (transaction + `refreshPostedState` + `confirm()` re-query each). O(total history) queries/hour, unbounded growth. **Add a mentions/link watermark.**
- **GAP-3 (Medium, AC-M3-017 not truly satisfied)** — `SendTaskRemindersCommand` (`:45-53`) only writes `reminder_sent_at` + an audit event; no assignee-facing surface consumes `reminder_sent_at` (the UI Overdue/Due-soon is driven by `due_at`), so "a reminder fires" is inert — the AC is effectively unmet and its test gives false confidence. Also stamps before the audit write with no transaction. **Decide: build an assignee-facing surface or mark AC-M3-017 deferred.**
- **GAP-4 (Low/Med, data quality)** — `days_to_post` (`NeonAnalyticsService.php:409`) has no floor; `posted_at` (earliest linked `published_at`) can predate `shipped_at` (manual link of pre-existing content) → **negative days_to_post** polluting average-days/post-rate. **Floor or reject `posted_at < shipped_at`.**

**Checked and cleared (don't spend follow-up here):** restamp GUC is correctly transaction-local (not a session leak); the "unavailable" literal is not an i18n hazard (single `<x-states.unavailable>` component, `APP_LOCALE=en`); `BrandRestrictionGuard` (AC-M3-007) is wired into *both* add paths; global `(platform, handle)` uniqueness *does* have a DB index (only the per-creator rule is app-only); CRM Livewire mutators consistently `$this->authorize(...)` — no broad missing-authz surface.

---

## Refuted findings (raised but did not survive ≥2/3 skeptics — recorded for transparency)

1. *Organic-variant shipments feed SEEDED classification, AC-M3-011 unenforced* (0/3) — refuted: SEEDED requires a proving record; organic runs don't emit one.
2. *Per-creator seeding views/EMV double-count vs run totals* (0/3) — refuted at the per-creator display layer (the real double-count is M2, at the product rollup).
3. *AC-M3-018/019 duplicate-ID collision blocks ADR-0016* (1/3) — refuted as pre-existing cosmetic doc issue, not caused here.
4. *Two-campaign content silently mono-attributed instead of null (D3 violation)* (1/3) — refuted: the single-campaign guard holds; the real issue is C1's sibling-mention leak.
5. *Upload path non-transactional → orphaned blob on row-create failure* (1/3) — refuted as low-probability with DP-005 blob tooling deferred.
6. *Brand-restriction block test flaky/one-variant* (0/3) — refuted: the guard is case-insensitive and covered on both paths.

---

## Sign-off

- **Reviewed:** 2026-07-07 (adversarial multi-agent pass).
- **review_status → REVIEWED.**
- **outcome:** ACCEPT WITH FIXES. C1 + C2 block trustworthy REQ-M3-009 reporting; H1/M1–M4 fix before the numbers are relied on; the rest are follow-ups. No security breach; no migration data-loss hazard confirmed.
- **Next:** product owner to authorize the fix pass (recommend C1, C2, H1, M1, M2, M3 as the first batch — they all converge on the seeding→campaign→results path).
