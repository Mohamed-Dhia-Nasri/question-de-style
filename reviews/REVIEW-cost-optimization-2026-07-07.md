# REVIEW TODO — Provider-cost governance implementation (ADR-0017)

Date: 2026-07-07. Scope: the full uncommitted diff implementing the Apify cost-optimization plan (`reviews/PLAN-apify-cost-optimization-2026-07-07.md`) — refresh-window polling, story dispatch fix + batching + kill switch, TikTok profile elimination, adaptive cadence, circuit breaker + retry hardening, permalink capture, campaign-linked direct-URL refresh, async Apify client. Decision record: ADR-0017 in `docs/05-decisions/decision-log.md`.

Verification state at handoff: full suite 666 passing (`XDEBUG_MODE=off php artisan test`), PHPStan clean, Pint clean. 25 new tests across `tests/Feature/Ingestion/{CostControlsTest,ProviderCircuitBreakerTest,StoryBatchTest,AdaptiveCadenceTest,CampaignRefreshTest}.php`.

> **STANDING TODO (deferred by operator, 2026-07-07): run the adversarial multi-agent review workflow over this full diff.** A run was started and stopped before completion; its completed agents are cached under run id `wf_89cd222b-041` (script: session `workflows/scripts/cost-optimization-review-wf_89cd222b-041.js`) — resuming in the same session replays cached lenses free. In a fresh session, launch a new workflow with the six lenses mirroring the checklist below (bookkeeping drift, retry/billing, adapters, story batch + async client, adaptive+refresh, KPI regressions + test honesty), each finding adversarially verified before reporting.
>
> **Scope extension (2026-07-07 evening):** the review MUST also cover the same-day follow-up work: (a) the home-dashboard wiring (`app/Modules/Monitoring/Livewire/Dashboard/HomeOverview.php`, `resources/views/livewire/monitoring/home-overview.blade.php`, `resources/views/dashboard.blade.php`, `tests/Feature/HomeDashboardTest.php` — check DP-001 tier labelling, ADR-0010 rollup-only aggregates, CLIENT_VIEWER containment); and (b) the canonical-doc amendment sweep (data-model, glossary, analytics-model, data-source matrix, module-3 XMC seam, ADR-0016 leftover annotations — verify amended text against code, links/anchors resolve, nothing renumbered).
>
> **Scope extension (2026-07-08): operator-chosen monitoring plan.** Tiered cadence + in-app plan settings (product-owner decisions: campaign creators 2×/day, baseline 2×/week, stories 1×/day, profiles weekly — all operator-changeable on /monitoring/plan). Review: `monitoring_plan_settings` migration/model, `CadenceSettings` (DB override → config fallback; per-instance resolution), reworked `AdaptiveCadence` (tier intervals; adaptive flag now governs ONLY the dormancy stretch), the story per-day gap gate in `RunMonitoringCycleJob` (min-gap spreading, -1h drift buffer), `IngestionCostEstimator` (price table: FREE/STARTER verified 2026-07-07, SCALE/BUSINESS interpolated), the `MonitoringPlanSettings` Livewire page (monitoring.manage gate, preview-estimate path), profile-interval planning flag (`includeProfile` threading, bookkeeping consistency), and `tests/Feature/Ingestion/MonitoringPlanTest.php`. Known soft spots for the reviewer: estimator's campaign-refresh line is a placeholder heuristic (~30% of cap); story per-day gate counts cycle rows, so a STALE-marked story cycle still occupies its gap window; CadenceSettings is resolved per job instance — long-lived workers see fresh settings per job, not mid-job.

## Review checklist

### Correctness
- [ ] `RunMonitoringCycleJob` rework: duplicate-cycle guard, full-depth sweep decision (`isFullDepthSweepDue` — interval semantics when a sweep cycle fails/stalls), story chunking and `jobs_expected` = chunk count.
- [ ] `PollPlan` shape vs. every dispatch site (`PollMonitoredAccountJob`, `RunCreatorCycleJob`) — can `jobs_expected` and actual dispatches drift under any platform mix / kill-switch state? (e.g. `stories_enabled` flipping between plan and dispatch.)
- [ ] Cycle-slot bookkeeping for `IngestStoriesBatchJob` (one chunk = one slot) including the no-batch-provider and breaker-skip paths.
- [ ] `ProviderCircuitBreaker` canary: `Cache::add` TTL vs cooldown; behavior when health row exists with NULL `last_error_category`; probe crash leaves slot taken for a full cooldown (accepted — verify acceptable).
- [ ] Replay guard `alreadySucceeded`: correlation ids are cycle-wide — confirm the (correlation, account, source, operation) key cannot false-positive across a retried cycle or an on-demand run overlapping a scheduled one.
- [ ] `TikTokContentAdapter` quiet-day item handling: an id-less item WITHOUT `authorMeta` must still quarantine; profile capture must not mask genuinely malformed items.
- [ ] `IngestContentJob` profile-from-content sync ordering: profile applied even when persister accepted 0 items? (currently yes — after recordCompletion); check `ProvidesProfileFromContent` state reset between fetches.
- [ ] `RefreshCampaignContentJob` eligibility query (whereExists over `shipment_resulting_content` → `shipments` → `seeding_campaigns`), settle-window `updated_at` proxy, orphan handling, `orderBy('updated_at')` rotation claim.
- [ ] `ApifyClient::runActorAsync`: status loop terminal states, deadline math, dataset pagination (items endpoint returns everything? large batches may need `limit/offset` paging — NOT implemented), 201-with-error-item handling on the dataset path.
- [ ] Story ownerHandle attribution: case-insensitivity via `mb_strtolower`, and the drop-if-ambiguous rule in multi-account batches.

### KPI-quality risks (from the plan's do-not list)
- [ ] Confirm re-poll-refreshes-metrics still holds end-to-end with the window on (persister update path + snapshot capture).
- [ ] Full-depth sweep actually reaches adapters (fullDepth flag threading through PollMonitoredAccountJob → IngestContentJob → fetchContent).
- [ ] Adaptive demotion can never starve a campaign-linked creator (exemption statuses chosen: Campaign ACTIVE/PLANNED; Seeding PLANNED/ACTIVE/SHIPPING — sanity-check against CRM lifecycle).

### Empirical follow-ups (need live Apify runs / operator action — NOT code review)
- [x] Rec 5 A/B: **DONE 2026-07-07 (~$0.24, zara/nike/natgeo) — reel scraper KEPT.** Post scraper missed in-window reels on 2 of 3 accounts and returned zero reels for zara; verdict recorded in the plan doc §rec 5.
- [ ] Verify the TikTok quiet-day profile-info item shape against a real date-filtered run (rec 4 risk note — rests on changelog quotes).
- [ ] Verify `resultsType: 'posts'` returns direct /reel/ URLs correctly (adapter currently sends all URLs under 'posts').
- [ ] Apify Starter subscription decision (rec 8) + story-actor access (allowlist email or plan).

### Governance
- [ ] ADR-0017 wording vs. actual code behavior.
- [ ] Data-source matrix amendment for the SRC-apify-instagram-scraper activation (pending, tracked with the Module 1 doc-amendment cluster).


---

## Verified adversarial review — RUN 2026-07-13

Multi-agent workflow (7 lenses → 3-skeptic majority-confirm over each finding). **13 confirmed** (mostly MEDIUM). No cross-tenant data leak, auth bypass, corruption, or crash. The pattern: several places infer "a cycle/sweep/slot ran" from a row's *existence or start-stamp* rather than a *successful outcome*, and the rec-9 replay/cost guard is bypassed on two paths.

**Verdict:** fundamentally sound; freshness/cost/telemetry gaps, all bounded and self-healing. The clear silent-data-loss items are FIXED with tests; retry-semantics and schema-change items are DEFERRED.

### FIXED (this session, with tests; full suite 874 green)
- **[MED] TikTok quiet-day guard silently dropped real videos on id drift.** An item that looks like a video (`videoMeta`/`webVideoUrl`/`playCount`) but has a non-string `id` now falls through to `Extract::requireString` and quarantines loudly instead of being mistaken for a marker and returned-as-null. Test: `ProviderNormalizationTest::test_tiktok_video_with_a_drifted_non_string_id_is_quarantined_not_silently_dropped`. (The genuine authorMeta-only marker still skips — unchanged.)
- **[MED] Status-blind story gap gate dropped a full day of stories.** The per-day gap gate now counts only cycles in `[Running, Completed, Partial]`; a wedged/STALE/FAILED story cycle no longer consumes the slot. Tests: `MonitoringCycleTest::test_a_stale_story_cycle_does_not_consume_the_daily_gap_slot` + `…_recent_completed_story_cycle_still_consumes_…`.
- **[MED] Full-depth sweep suppressed for an interval after a failed/empty sweep.** `isFullDepthSweepDue()` now requires a `[Completed, Partial]` full-depth cycle, so a stalled/zero-job sweep no longer counts as "done".

### DEFERRED (recorded; design/schema decisions — not quick fixes)
- **[MED] Cross-tenant plan bleed:** `MonitoringPlanSetting::current()` is global-latest and the scheduler runs tenant-less, so one tenant's plan drives every tenant's cadence + shared Apify cost. **Must be gated before `QDS_BILLING_ENFORCED` goes on.** Fix = resolve the plan per tenant inside the cycle fan-out (`runAs` + per-tenant `CadenceSettings`).
- **[MED] Deleted/private campaign posts starve the refresh rotation and re-bill every run** (`orderBy(updated_at)` never advances a post Apify can't fetch). Needs a `last_refresh_attempted_at` marker column (migration).
- **[MED] A hard-deleted account in `PollMonitoredAccountJob` never decrements its counted slots** → the cycle wedges Running until reaped (~3h roster-wide freshness freeze). Bookkeeping fix; moderate risk.
- **[MED] Transient failure of one Instagram sibling abandons its content** until next cadence (`&& $allFailed` makes the purpose-built replay guard unreachable). Fix = drop `&& $allFailed`; **needs a dedicated retry-behaviour test** — an in-flight code note marks the gap in `IngestContentJob`. (Attempted this session, reverted to avoid destabilizing the cost-sensitive retry path in a batch.)
- **[MED] Billed call left with no ProviderCall row + re-billed if persistence throws** (persistence is outside the telemetry try/catch). Moderate risk; wrap persistence.
- **[LOW] `stories_enabled` flip between plan and dispatch** orphans a counted story slot (narrow race). Thread the plan-time decision like `includeProfile`.
- **[LOW] Story items whose `ownerHandle` matches no batch account** are dropped uncounted (case-fold only). Log/count unmatched handles.
- **[LOW] HomeOverview "Mentions (30d)" (exact) vs "Estimated reach (30d)" (weekly-grain)** span different windows under one label. Cosmetic; relabel or align.
- **[INFO] Campaign-refresh estimator line** is a `*0.3` placeholder folded into the total, roster-independent, not covered by `approx`. Scale by roster / flag as estimated.

### Invariants CLEARED
The replay guard is correct on the path it reaches; persistence is idempotent upsert so the cadence/freshness gaps self-heal next cycle with no corruption; no cross-tenant DATA disclosure (only cadence/cost coupling); the IngestionCycle ledger status transitions + stale-reaping are correct; under the current string-id contract both TikTok and Instagram validate-before-persist correctly; story handle attribution reconciles case variants; the plan page honors its estimate framing.

### Coverage gaps (need live Apify runs / operator action)
Whether TikTok drift is currently active depends on the live actor schema (string vs numeric `id`); `runActorAsync` non-streaming OOM rests on assumed payload sizes; ownerHandle-mismatch and config-flip frequencies are deployment-dependent; the campaign-refresh re-billing dollar cost needs Apify billing data; the cross-tenant cadence bleed only manifests once ≥2 tenants have saved plans (a governance gate decision); the story-loss window depends on the deployed cadence + provider reliability.
