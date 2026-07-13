<!--
  BUILD SPEC (pre-implementation) — working artifact, NOT canonical docs.
  Written and implemented in the same session (2026-07-06) by the
  implementation model; the deep review happens afterwards via
  reviews/REVIEW-module3-results-docs-tasks-<date>.md.
-->

# Build Spec — Module 3 (CRM & Seeding), Step 4 of 4: Results, Analytics Feeds, Documents & Tasks

- **phase:** P3 · **service:** `SVC-CRM` (+ loaders in `SVC-Analytics`, exports in `SVC-Export`)
- **spec:** `docs/50-modules/module-3-crm-seeding.md` §2.8 (REQ-M3-009), §2.9 (REQ-M3-010/011), §2.11 (REQ-M3-013); `docs/30-data-model/01-analytics-model.md` §4–5
- **prereq read (Rule 1):** same reading order as Steps 1–3, plus the three PENDING_REVIEW handoffs (`reviews/REVIEW-module3-*.md`).

Step 4 closes Module 3: campaign & seeding **results** (content count, PUBLIC views/likes/comments, DERIVED engagement, ESTIMATED reach tiering, EMV, CPE, CPM — AC-M3-014/015), the **FACT-Shipment / FACT-SeedingContent loaders** plus rollup read path that feed the reporting dashboards (AC-M3-018 results / AC-M3-019 product totals), the **product-level cross-influencer aggregation UI** (REQ-M3-013), **documents** (AC-M3-016) and **tasks with deadline reminders** (AC-M3-017), and a seeding-results **export** kind.

**Scope decision (product owner, 2026-07-06): the agency will have NO external clients.** The `CLIENT_VIEWER` external login / approved-report surface (module-3 §1.1(6), §2.10, roles-section AC-M3-019) is **dropped from v1** — recorded as **ADR-0016** (this step writes it; see §2.8). No client-facing surface is built. The rest of REQ-M3-012 (ADMIN-only User/Role writes, single-role enforcement) already shipped in P0/Step 1.

---

## 1. Current state (do not rebuild)

- **Star schema is P0-complete and empty on the seeding side.** `fact_shipment` + `fact_seeding_content` tables exist (`database/migrations/analytics/2026_07_05_200002_create_analytics_facts.php:111-159`), partitioned + append-only (trigger :215-221); all dims exist incl. `dim_product`/`dim_campaign`/`dim_seeding_campaign`/`dim_geo`; all seven canonical rollups exist and are already refreshed by `NeonAnalyticsService::ROLLUPS` — they are simply empty because the two fact loaders are pending (`app/Platform/Analytics/NeonAnalyticsService.php:23-26`, loaders :56-60 load only content-metric/creator-account/mention facts). Watermark mechanics: `watermark()`/`advanceWatermark()` (:329-347). `fact_mention` already carries `campaign_id`, per-mention PUBLIC metrics, `estimated_reach(+_tier)`, `emv(+_tier/_currency)`.
- **`rollup_seeding_by_product`** (`2026_07_05_200003:281-337`) is exactly the AC-M3-019 shape: grain×bucket×product → shipments, posted_count, post_rate (recomputed DERIVED), creators_reached, content_count, total_views, total_estimated_reach [ESTIMATED], total_engagement, total_emv [ESTIMATED]. It has **no platform/content-type/country columns** (see D5). Companions: by_shipment, by_creator_campaign, by_brand.
- **EMV engine** (`SVC-EnrichmentAI`): `EmvCalculator` → append-only `emv_results` self-carrying `formula_version`/`rate_card_version`/`currency`/`inputs`/`assumptions`; EMV is always tier **ESTIMATED**; NULL config ⇒ unavailable, never zero. Disclosure precedent: `content-detail.blade.php:120-124` and `ReportBuilder` disclosures (:79-111).
- **Engagement/reach doctrine**: raw engagement sum = `likes+comments+shares+saves` over the latest snapshot per (shipment×content) — already encoded in the rollups; DERIVED ratios recomputed at grain, never summed (`RollupReader.php:97-99`). Reach: `ReachEstimator` bound to `UnavailableReachEstimator` ⇒ reach is NULL everywhere; estimated-reach surfaces render **"unavailable"** (DEF-003) — not zero.
- **RollupReader** (`app/Platform/Analytics/RollupReader.php`) is the only sanctioned dashboard read path (ADR-0010; dashboards/exports never read FACT-*/OLTP): follow `mentionTotals()` (:78-94) — NULL ⇒ unavailable, tier columns pass through. `GRAINS = ['week','month','quarter','year']` (:20).
- **Documents & tasks substrate shipped in Step 1**: `document_attachments` (creator_id/campaign_id nullable FKs, file_name, storage_url, uploaded_at) and `tasks` (title, status CHECK on ENUM-TaskStatus, assignee_user_id nullOnDelete, due_at, creator_id, campaign_id) with models, factories, policies **already Gate-wired** in `CrmServiceProvider:97-98`. **No UI, no upload/download plumbing, no reminder mechanics.**
- **Export pipeline is extensible by registry**: add a const + section builder in `app/Platform/Export/ReportBuilder.php`, it flows through `ExportManager`/`GenerateExportJob`/signed download automatically; `ReportFilters` whitelists `grain/from/to/brand_id/creator_id`. Private-disk + signed-URL download precedent: `ExportDownloadController`, `exports.download` route.
- **UI substrate**: `WithDataTable` trait, `x-metric.tier-badge` / `x-metric.value` / `x-states.unavailable` components, KPI-card + filter idioms in `MonitoringOverview`, CRM CRUD idiom in `CampaignsIndex` (authorize in mount + EVERY mutator, `Rule::in` closed sets, `AuditLogger` events incl. `*.status_changed` from→to, `notify` dispatches, restrict-FK delete handling). Landing placeholder to replace: `resources/views/crm/index.blade.php:70-76` ("Results & Reporting … ship in Step 4").
- **No spend/budget field exists anywhere** (schema or canon ENT shapes). Canon *presumes* "agency-entered spend" for CPE/CPM (`00-data-model.md:587`).

## 2. Build

### 2.1 Spend input (prerequisite for AC-M3-015)

- Migration: `spend` jsonb nullable on **campaigns** and **seeding_campaigns**, cast `AsValueObject::class.':'.MetricValue::class`, written as `new MetricValue((float)$v, MetricTier::Confirmed, 'spend')` — exact `products.unit_value` precedent (`2026_07_05_230001:29`, `ProductsIndex:132-133`). Form field ("Spend (agency input, CONFIRMED)") in `CampaignsIndex` + `SeedingCampaignsIndex`; nullable, `numeric|min:0`; audited via the existing updated events. **D1 deviation** — no canonical ENT field; amendment candidate (00-data-model ENT-Campaign/ENT-SeedingCampaign + the :587 prose).

### 2.2 Analytics feeds — FACT loaders + rollup read path (REQ-M3-009/013 plumbing)

- **`loadShipmentFacts()`** in `NeonAnalyticsService`: dispatched shipments only (`shipped_at` NOT NULL — mirrors Step 3 D1), `date_key = shipped_at::date` (canon DIM-Date(shippedAt)); measures `shipped=1`, `posted` 0/1, `quantity`, `product_value` (from `product_value_at_ship->>'amount'`), `days_to_post` (postedAt−shippedAt, days); dims creator/product/brand/client/seeding_campaign/campaign. UNIQUE (shipment_id, date_key).
- **`loadSeedingContentFacts()`**: grain shipment × resulting content × metric-snapshot bucket (pivot `shipment_resulting_content` × `metric_snapshots` on content), `date_key` per canon DIM-Date(postedAt) with snapshot bucketing following the existing `loadContentMetricFacts` convention; PUBLIC metrics via `qds_public_metric()`; `estimated_reach` stays NULL (UnavailableReachEstimator; tier column NULL); `emv(+tier/currency)` via the established latest-`emv_results` lateral join (:307-315); platform/content-type/geo dims from the content item.
- **Append-only mechanics (D2):** study `loadMentionFacts()` first and replicate its watermark + re-materialization semantics exactly (facts are INSERT-only with UNIQUE keys; whatever mechanism it uses for rows whose source mutated — e.g. watermark on `updated_at` + delete-partition-reinsert or conflict-skip — copy it, don't invent). Shipments mutate (`posted` flips): the loader must handle re-stamping the same way `fact_mention` handles mention updates. Document the choice in the handoff.
- Update the class-header comment (loaders no longer pending); watermark rows named like the existing ones.
- **New additive rollups (D3, D5 — both flagged canon-amendment candidates to analytics-model §5):**
  - **`rollup_mention_by_campaign`** — campaign results feed (REQ-M3-009 campaign half): mirror `rollup_mention_by_brand` (:116) but grouped by `campaign_id` (non-null only): mention_count, content_count, views/likes/comments (+shares/saves for the engagement sum), total_engagement, total_emv [+tier], total_estimated_reach [+tier], per grain×bucket. New migration in `database/migrations/analytics/`; register in `ROLLUPS`; unique index like the siblings.
  - **`rollup_seeding_by_product_slice`** — same measures as `rollup_seeding_by_product` but with `platform`, `content_type`, `country` dimension columns (from `fact_seeding_content`), serving module-3 §2.11 "sliceable by platform, content type, and country" (AC-M3-019 "any time grain and slice") without touching the canonical P0 view. Content-side measures only where a slice implies content (shipment counts stay on the unsliced view; the slice view's shipment columns re-aggregate from `fact_shipment` only for the unsliced total row — keep it simple: slice view = content measures + creators_reached; the dashboard combines).
- **`RollupReader` additions** (same NULL⇒unavailable discipline, week-grain default, tier columns passed through): `seedingByProduct($grain,$from,$to,$brandId,$productId)`, `seedingProductSlices(...)` (+platform/contentType/country filters), `seedingByShipment($seedingCampaignId)`, `seedingCampaignTotals($seedingCampaignId)` (sum by_creator_campaign / by_shipment for one run), `campaignMentionTotals($campaignId,$from,$to)` (from `rollup_mention_by_campaign`).
- Tests: `tests/Feature/Analytics/` — loader idempotency/watermark, per-fact shape (a seeded shipment+content+snapshot graph produces the canonical row), rollup outputs (post_rate recomputed, tier columns), reader NULL⇒null semantics. Follow the existing analytics test idiom.

### 2.3 Results panels (REQ-M3-009, AC-M3-014/015 + results-section AC-M3-018)

- **`crm.campaign-results`** panel on the campaign detail page and **`crm.seeding-results`** panel on the seeding detail page (Livewire, `UsersIndex`-family conventions; authorize `view` on the parent in mount). **All aggregates come from `RollupReader` only** (ADR-0010): campaign panel ← `campaignMentionTotals` (+ per-seeding-run rows via `seedingCampaignTotals` for its child runs); seeding panel ← `seedingCampaignTotals` + per-shipment rows (`seedingByShipment`) + per-creator rows.
- Rendered numbers with tiers (DP-001): content count (PUBLIC-derived count, no badge needed beyond convention), views/likes/comments **PUBLIC**, engagement sum **DERIVED**, estimated reach **ESTIMATED or "unavailable"** (currently always unavailable — DEF-003 + null loader values; reason cites DEF-003), true unique reach **"unavailable" (DEF-003)** always, EMV **ESTIMATED** + disclosure line (active `EmvConfiguration` name · formula version · rate card version · rates — the `content-detail.blade.php:120-124` convention; "unavailable" when no active config).
- **CPE/CPM (AC-M3-015):** computed at display time in the panel (never stored, never summed — analytics-model :54/:133): `CPE = spend.amount / total_engagement`, `CPM = spend.amount / (total_views/1000)`; **DERIVED** badges (weakest-input rule `00-data-model.md:587`: CONFIRMED spend ÷ DERIVED/PUBLIC aggregates); NULL/0 divisor or missing spend ⇒ `x-states.unavailable` (reason: "requires agency-entered spend" / "no engagement observed yet") — never zero, never ∞ (D4).
- Empty states: campaign with no linked mentions / run with no shipments show `x-states.empty`-style zero-counts where zero IS a real measurement (content_count 0), and "unavailable" only for deferred/absent inputs.

### 2.4 Product aggregation dashboard (REQ-M3-013, AC-M3-019)

- **`crm.seeding-results-dashboard`** at **`/crm/results`** (route in the existing `can:crm.view` group; landing card at `crm/index.blade.php:70-76` becomes a live link): the cross-influencer product totals — one row per product from `seedingByProduct`: shipments, posted_count, post_rate (DERIVED badge), creators_reached, content_count, total_views [PUBLIC], total_estimated_reach [ESTIMATED, "unavailable" while null], total_engagement [DERIVED-sum], total_emv [ESTIMATED].
- Filters (MonitoringOverview idiom, `#[Url]` + server-side validation): grain (`RollupReader::GRAINS`, default month), from/to, brand, product, and slice selectors platform / content type / country (drive `seedingProductSlices`; when a slice is active the slice-scoped content measures render and shipment-level columns show "—" with a caption, since shipments carry no platform). "Rollups refreshed {diffForHumans}" caption; estimates always labelled (DP-001).
- EMV disclosure line as in §2.3.

### 2.5 Seeding-results export (SVC-Export)

- `ReportBuilder::SEEDING_RESULTS = 'seeding-results'` + section builders over `rollup_seeding_by_product` (+ by_shipment detail section), tier-suffixed column names, disclosures: tier legend + CPE/CPM formulas + EMV model/rates (or Unavailable) + DEF-003 reach note. Register in `reports()`/`build()` match — `ExportManager` picks it up; verify `ExportsIndex` lists kinds from `ReportBuilder::reports()` (extend the select if hardcoded). Existing `ReportFilters` keys suffice (grain/from/to/brand_id); a product filter is optional future work (flag, don't build).

### 2.6 Documents (REQ-M3-010, AC-M3-016)

- Migration: nullable `seeding_campaign_id` FK on `document_attachments` (**D6 deviation** — canonical ENT shape has creator/campaign only, but module-3 §2.9 + AC-M3-016 say "creators, campaigns, or seeding runs"; amendment candidate). Model/factory updated.
- **`crm.documents-panel`** — ONE reusable Livewire component mounted with exactly one parent (`creator:` on the profile, `campaign:` on campaign detail, `seedingCampaign:` on seeding detail): list (file name, uploaded at, size if cheaply available) + upload + delete.
  - Upload: `WithFileUploads`; validation `file|max:10240` + extension allowlist (pdf, doc/docx, xls/xlsx, csv, png, jpg/jpeg, zip — **D7**, operational choice, flag); stored via `Storage::disk(config('qds.documents.disk'))->putFile('documents/...')` on the **private local disk** (new `qds.documents` config block, disk default `local` — export precedent); `storage_url` = the storage path; `file_name` = original client name; `uploaded_at = now()`; authorize `create` on `DocumentAttachment::class`; audit `document.uploaded` (uploader identity lives in the audit row — canon has no uploadedBy field).
  - Download: signed route + `DocumentDownloadController` streaming from the disk (exports precedent: `middleware(['web','auth','signed'])`), authorizing `view` on the attachment; never a public URL.
  - Delete: authorize `delete`, remove blob + row in a transaction, audit `document.deleted`.
- Tests: upload/download/delete happy paths + authz (view-only user forbidden incl. direct-mutator bypass), validation failures, panel on all three parents, blob actually stored/removed (`Storage::fake`).

### 2.7 Tasks (REQ-M3-011, AC-M3-017)

- Migration: nullable `reminder_sent_at` timestamp on `tasks` (**D8 deviation** — not in canonical ENT shape; needed so a reminder fires exactly once; amendment candidate).
- **`crm.tasks-index`** at **`/crm/tasks`** (+ landing card with open-task count): WithDataTable list with status filter, assignee filter, due-window sort; **Overdue** and **Due soon** visual sections/badges; CRUD modal (title, ENUM-TaskStatus `Rule::in` closed set, assignee user select, due_at, optional creator/campaign links); status changes audited `task.status_changed` from→to (campaigns precedent); deletes audited.
- **`crm.tasks-panel`** — compact reusable panel on creator profile + campaign detail (same component pattern as documents; list + quick add + status flip).
- **`qds:send-task-reminders`** command (**D9**): for tasks with `due_at <= now()+window` (window `config('qds.tasks.reminder_window_hours')`, default 48), status in OPEN/IN_PROGRESS/BLOCKED, `reminder_sent_at` NULL → stamp `reminder_sent_at`, audit **`task.reminder_fired`** (assignee + due_at in context). The **reminder channel is in-app**: the fired state renders as the prominent Due-soon/Overdue affordance in the tasks UI (badge + section) — no email/push exists in the frozen stack (flag: channel canon-silent). Schedule hourly in `routes/console.php` (cadence NOT canonically decided — same flagged class as the others). Command is idempotent (the stamp) and safe to run unconditionally (no config gate needed; it only writes when something is due).
- Tests: CRUD + closed-set validation + audits + authz/bypass; reminder command — fires once (idempotent), respects window/status, audit row shape; due-soon/overdue rendering.

### 2.8 Governance — ADR-0016 + cross-references (docs work, this step)

- **ADR-0016 — "No external client access in v1 — CLIENT_VIEWER surface dropped"** in `docs/05-decisions/decision-log.md`, ADR-0015 four-part format: **Context** — REQ-M3-012/AC (roles-section AC-M3-019), module-3 §1.1(6)/§2.10/§5-mermaid and roadmap P3/P4 presuppose external client logins viewing approved reports; product-owner decision 2026-07-06: the agency will have **no external clients**. **Decision** — v1 ships no client login, no approved-report surface, no report-approval workflow; `CLIENT_VIEWER` stays a defined `ENUM-RoleName` value whose access is deny-everything (already enforced + regression-tested as the negative authz case); the P4 "white-label client reports" deliverable is void unless this ADR is superseded. **Status** APPROVED. **Consequences** — REQ-M3-012 is satisfied by ADMIN-only User/Role management + deny-all CLIENT_VIEWER; roles-section AC-M3-019 deferred out of v1; **no new DEF-\*** (feature removal, not unavailable data — ADR-0014 precedent); `reports.view-approved` permission stays in the catalog unused.
- Ledger row + intro count bump ("All sixteen ADRs (`ADR-0001` .. `ADR-0016`)"); cross-reference notes at: module-3 §1.1(6) + §2.10 + roles AC-M3-019 + §6.3, req-matrix REQ-M3-012 row, modules-overview REQ-M3-012 row, roadmap :310-311 + P4 white-label lines. Same propagation class as ADR-0014's. **No other canonical edits** — D1/D3/D5/D6/D8 stay flagged deviations in the handoff + deviations memory, awaiting doc amendments.

## 3. Decisions (implementer-flagged, reviewer to confirm)

- **D1** — `spend` field added to campaigns + seeding_campaigns (jsonb MetricValue, tier CONFIRMED, metric 'spend'); canon presumes agency-entered spend but defines no field.
- **D2** — fact-loader mutation semantics copied from `loadMentionFacts()` (whatever the established mechanism is), not invented; documented in the handoff.
- **D3** — `rollup_mention_by_campaign` added (campaign-half of REQ-M3-009 has no canonical rollup; ADR-0010 forbids FACT/OLTP dashboard reads — additive view mirroring rollup_mention_by_brand).
- **D4** — CPE/CPM are DERIVED (weakest-input: CONFIRMED spend ÷ DERIVED/PUBLIC totals), computed at display grain, never stored; NULL/0 divisor ⇒ "unavailable".
- **D5** — slices served by additive `rollup_seeding_by_product_slice` (platform/content_type/country) rather than altering the canonical P0 view.
- **D6** — `document_attachments.seeding_campaign_id` added (module prose + AC name seeding runs; ENT shape doesn't).
- **D7** — upload constraints (10 MB, fixed extension allowlist, private local disk, signed download) are operational choices.
- **D8** — `tasks.reminder_sent_at` added for fire-exactly-once reminders.
- **D9** — reminder channel is in-app (audit event + Due-soon/Overdue UI); no email/push in the frozen stack; hourly cadence flagged non-canonical.

## 4. Acceptance criteria for Step 4

- **AC-M3-014** — completed campaign shows content count + PUBLIC views/likes/comments with correct tier tags; engagement DERIVED; reach ESTIMATED-labelled (currently "unavailable"); true unique reach "unavailable" (DEF-003).
- **AC-M3-015** — with agency spend entered: CPE = spend/engagement, CPM = spend/(views÷1000), EMV shown from SVC-EnrichmentAI **with model + rates disclosed**.
- **AC-M3-018 (results)** — posted shipment's resulting content tracked over time via FACT-SeedingContent rows.
- **AC-M3-019 (product)** — ROLLUP-SeedingByProduct shows one cross-influencer total per product for any grain and slice; no estimate presented as fact.
- **AC-M3-016** — uploaded contract/brief links to creator/campaign/seeding record; **AC-M3-017** — nearing deadline fires a reminder exactly once; task status stays in ENUM-TaskStatus.
- Roadmap P3 exit lines: "Results compute content count, views, engagement, tiered reach, EMV, CPE, CPM" + "Documents, tasks, and full role-based permissions enforce correctly."
- ADR-0016 written + cross-referenced; docs linter conventions respected on touched docs.
- Green bar: full suite + PHPStan level 6 + Pint, `XDEBUG_MODE=off`.

## 5. Out of scope

- CLIENT_VIEWER login, report-approval workflow, white-label reports (ADR-0016).
- Courier APIs; merge/reassignment (ADR-0014); CONFIRMED reach (DEF-003/DEF-004); email/push notification channels.
- Canonical doc amendments beyond ADR-0016 + its cross-references (deviations stay ledger-flagged).
- Product filter on ReportFilters (flagged optional future work).

## 6. Handoff

On completion write `reviews/REVIEW-module3-results-docs-tasks-<YYYY-MM-DD>.md` from `reviews/_TEMPLATE.md` (PENDING_REVIEW). **Per the product owner's instruction (2026-07-06), the in-session multi-agent adversarial (Ultracode) review workflow is intentionally DEFERRED for Step 4** — the handoff must carry the same mandatory TODO banner as Step 3's: the independent deep review (canon/ownership/security/correctness/test sweeps) is required before this step is treated as accepted, and Steps 1–3 reviews are still pending too.
