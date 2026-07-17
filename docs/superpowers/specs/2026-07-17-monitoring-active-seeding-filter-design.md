# Monitoring Page — "Active Seeding Only" Filter (Design)

- **Date:** 2026-07-17
- **Status:** Design approved in brainstorming; awaiting implementation plan.
- **Page:** `/monitoring` (`monitoring.index` → `App\Modules\Monitoring\Livewire\Dashboard\MonitoringOverview`).

## Goal

Let the user flip the monitoring overview between two views of the same numbers:

1. **All creators** (today's behavior, unchanged) — everything on the tenant's roster.
2. **Active seeding only** — the same cards, re-scoped to creators enrolled in a seeding campaign that is currently running.

This answers "is our seeding spend producing content, views, engagement, and mentions?" using the page that already exists, instead of a new page.

## Locked decisions

| Decision | Choice |
| --- | --- |
| Control | Single on/off checkbox **"Active seeding only"** in the existing filter bar. |
| URL binding | `#[Url]` boolean: `?activeSeedingOnly=1`, like the existing filters — shareable and bookmarkable. |
| "Active" definition | `SeedingCampaignStatus` **ACTIVE + SHIPPING**, owned by one constant (`ActiveSeedingCreatorIds::ACTIVE_STATUSES`). The `/dashboard` "active seeding runs" tile (`HomeOverview`) is refactored in this feature to read that same constant, so the two pages cannot drift apart. |
| Creator-set source | **Enrolled list**: the `seeding_campaign_creator` pivot (creators officially added to the campaign), *not* shipments. |
| Scope | **Approach A**: re-scope the five card groups listed below; be explicit where scoping is impossible. No new analytics rollup in this feature. |

## Behavior

**Toggle OFF (default):** zero numeric or data change — every value on the page is identical to today. (The only intentional interaction change is the date-input debounce described under Blade, which applies regardless of the toggle.)

**Toggle ON:** the creator set = distinct `creator_id`s in `seeding_campaign_creator` whose campaign has status ACTIVE or SHIPPING, for the current tenant. The following five card groups re-scope to that set (Views and Engagement are two KPI tiles fed by the same `creatorTotals()` call — both scoped):

| Card group | How it re-scopes |
| --- | --- |
| Monitored creators (roster) | `whereIn('creator_id', $ids)` on `MonitoredSubject` (direct column). |
| New content in period | `whereHas('platformAccount', whereIn('creator_id', $ids))` on `ContentItem`. |
| Active stories | Same `whereHas('platformAccount', …)` on `Story`. |
| Mentions by type | `whereHas('monitoredSubject', whereIn('creator_id', $ids))` on `Mention`. |
| Views + Engagement KPI tiles | `RollupReader::creatorTotals()` filtered by the creator-ID set (see Components). |

**Cards that do NOT re-scope (by design):**

- **Estimated Reach + EMV** — their rollup (`rollup_mention_by_brand`) is pre-summed by brand and has no creator dimension. When the toggle is ON, these two cards render **no numeric value at all** — only the message *"Aggregated by brand — not available for the seeding filter."* A greyed-out brand-wide number is not acceptable: it would show an unscoped figure under a seeding filter. If the rollup is *also* unavailable while the toggle is ON, the seeding-filter message wins. The seeding-filter state and the existing "rollup unavailable" state must render visibly different copy/markup. Adding a per-creator reach/EMV rollup is a documented follow-up (see Out of scope).
- **Pending reviews** — a global queue count; stays global.
- **Provider / ingestion health** — infrastructure telemetry with no creator dimension; stays global.
- **Deferred-capability cards** — static; unchanged.

**Filter interplay:** the toggle is orthogonal to platform / brand / date — it intersects the creator set; the other filters keep applying exactly as they do now. As today, the roster count ignores platform/brand/date; it responds only to the toggle.

**Empty set:** if no creators are enrolled in an active seeding while the toggle is ON:

- The four count cards (roster, new content, active stories, mentions by type) show **0** — a count of nothing is a real zero.
- The Views/Engagement KPI tiles render the page's existing **unavailable** state, per DP-001: an aggregate over no rows comes back `null`, never a fabricated zero — exactly how these tiles already behave when a restrictive date range matches nothing. The page-level notice (below) explains why.
- The page shows a small inline notice: *"No creators are currently in an active seeding."* The notice is gated **only** on (toggle ON) AND (resolved creator set is empty). It never shows when the toggle is OFF, and it never shows when creators are enrolled but their counts happen to be zero for other reasons (e.g. the date range excludes everything).
- The filter must never silently fall back to unfiltered data (see the empty-array guard below).

## Components

### `ActiveSeedingCreatorIds` (new, CRM-owned read helper)

- One public method: `forCurrentTenant(): array` — distinct creator IDs enrolled (pivot) in seeding campaigns whose status is in the class constant `ACTIVE_STATUSES = [Active, Shipping]`. That constant is the single owner of the "active" definition; `HomeOverview` (the `/dashboard` tile) is updated to read it.
- Built on Eloquent (`SeedingCampaign` uses `BelongsToTenant`), so tenant scoping is automatic — no raw SQL. Reference join shape: `IngestionCostEstimator::rosterFromDatabase()`.
- Lives in the CRM module because `SeedingCampaign` is CRM-owned; Monitoring already reads CRM models, so the dependency direction is consistent.

### `MonitoringOverview` (Livewire component)

- New property: `#[Url] public bool $activeSeedingOnly = false;`.
- Resolve the creator-ID set **exactly once per render**: a single `ActiveSeedingCreatorIds` lookup at the top of `render()`, its result threaded into every card — never one lookup per card.
- **Empty-array guard (correctness-critical):** every card gates its scoping on the **boolean** (`$this->activeSeedingOnly`), never on array truthiness. `->when($creatorIds, …)` would treat an empty array as "no filter" and silently show unfiltered data when the toggle is ON with zero active campaigns. Toggle ON + empty set must filter to zero rows.
- **In-scope housekeeping:** call `ReviewQueue::counts()` **once** per render and reuse the result — it is currently called twice. No behavior change; covered by a test asserting the displayed values are unchanged.

### `RollupReader::creatorTotals()`

- Current signature: `creatorTotals(?Carbon $from = null, ?Carbon $to = null, ?int $creatorId = null)`. The `?int $creatorId` parameter is unused by any caller today (verified: the only call site is `MonitoringOverview` passing two arguments). **Generalize it in place to `?array $creatorIds = null`** (do not add a fourth parameter next to a near-identically named one). Backward-compatible: no call site passes the third argument.
- Contract: `null` = no creator filter (today's behavior); non-null = `whereIn('creator_id', $creatorIds)`. An empty array matches no rows, so the SQL sums come back **`null`** — which is the class's documented contract (NULL ⇒ unavailable, never zero, per DP-001) and flows into the tiles' existing unavailable rendering. Do **not** fabricate a zero-valued object.

### Blade (`livewire/monitoring/monitoring-overview.blade.php`)

- Toggle in the filter bar (the project's `x-form.toggle` component — a styled checkbox), `wire:model.live` (a discrete one-click control — instant reload is fine).
- **Date inputs (`from`, `to`): change `wire:model.live` → `wire:model.blur`**, so typing a date doesn't fire a full re-render per keystroke. The platform and brand selects **stay `wire:model.live`** — a select is a discrete one-click control, like the new checkbox.
- Reach/EMV "not available for the seeding filter" state, gated on `$activeSeedingOnly` (message only, no number — see Behavior).
- Empty-set inline notice.

## Performance

Verified against the real schema and realistic volumes (Postgres-only; the analytics layer requires it):

- Every join column the filter touches is already indexed (`content_items.platform_account_id`, `stories.platform_account_id`, `mentions.monitored_subject_id`, `platform_accounts.creator_id`, `monitored_subjects.creator_id`, the pivot's unique index). The `whereHas` clauses run as index-backed semi-joins, and the toggle *narrows* the scanned set.
- **Array approach over inline subquery:** resolve IDs to a PHP array once, bind `whereIn` in each card. The seeding join runs once per render instead of being re-planned inside five statements. The set is hard-bounded by the tenant's creator count (~hundreds), far below Postgres's 65,535-parameter ceiling — no chunking.
- **New index:** `seeding_campaigns (tenant_id, status)` — the resolver's driving filter; `status` is currently unindexed. **A migration adding this index ships with this feature.**
- The dominant page cost is pre-existing `.live` re-render thrash plus the doubled `ReviewQueue::counts()`; both are addressed above.

## Demo data

**No seeder change needed** (an earlier draft of this spec claimed otherwise; verified against the code on 2026-07-17):

- `DemoDataSeeder::seedSeedingAndShipments()` already attaches recipients to the `seeding_campaign_creator` pivot via `$seeding->creators()->syncWithoutDetaching(...)` (the F03 repair), for every campaign except DRAFT/PLANNED.
- Its plan creates **4 ACTIVE + 2 SHIPPING** campaigns with 8–20 recipients each, so the toggle shows a non-empty set on freshly seeded demo data.
- Older local databases are repaired by the existing migration `2026_07_17_100001_backfill_seeding_campaign_creator_from_shipments.php`, which backfills the pivot from shipments.

## Tenancy and permissions

- The resolver is Eloquent-on-`SeedingCampaign` (tenant global scope) and the pivot carries `tenant_id`; all downstream models are tenant-scoped. An explicit cross-tenant isolation test is required (see Testing) given the project's isolation guarantees.
- No new permission. `monitoring.view` still gates the page; the toggle only filters data the user can already see and exposes no seeding-campaign details.

## Testing

1. **Resolver unit tests** — returns only creators of ACTIVE + SHIPPING campaigns; excludes every `SeedingCampaignStatus` other than ACTIVE and SHIPPING (currently DRAFT, PLANNED, COMPLETED, CANCELLED — iterate the enum, don't hardcode the list); de-duplicates a creator enrolled in two active campaigns; tenant-scoped.
2. **Livewire feature tests** — toggle OFF: all values identical to today. Toggle ON: each of the five scoped card groups reflects only enrolled creators; Reach/EMV show the seeding-filter message with no numeric value, and that state renders different copy/markup from the "rollup unavailable" state; pending reviews and provider health unchanged; URL round-trip (`?activeSeedingOnly=1` restores the toggle).
3. **Empty-set tests** — toggle ON with zero active campaigns → the count cards read zero and the Views/Engagement tiles render the unavailable state (never unfiltered values), and the notice renders. Negative assertions: no notice when the toggle is OFF; no notice when creators are enrolled but counts are zero for other reasons (e.g. restrictive date range).
4. **`RollupReader` tests** — `creatorTotals(creatorIds: [...])` sums only the subset; **empty array → `null` sums (DP-001), never a fabricated zero**; `null` → unchanged behavior.
5. **Cross-tenant isolation test** — tenant B's active seeding never leaks into tenant A's toggled page.
6. **Interplay test** — toggle + date/platform/brand combine as an intersection (roster card exempt — it ignores platform/brand/date by design and responds only to the toggle).
7. **Housekeeping tests** — review-counts values unchanged after the single-call fix; `/dashboard` "active seeding runs" tile unchanged after reading `ACTIVE_STATUSES`.

## Out of scope (documented follow-ups)

- **Per-creator Reach/EMV** (`rollup_mention_by_creator` materialized view) — would let the last two KPI cards re-scope too ("Approach B"). A separate analytics feature.
- **Default date bound for the page** (e.g. last 90 days) — would stop the new-content and mentions cards from scanning full tables on first load. Helps performance more than any seeding-specific tuning, but it changes what the page shows today, so it is deliberately excluded here.
- **`mentions (tenant_id, created_at)` index** — pre-existing gap on the date filter, not introduced by this feature.
- **Scoping the pending-reviews count by creator** — possible but needs per-kind plumbing in `ReviewQueue`; not worth it for this feature.
