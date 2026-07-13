<!--
  Deep-Review Handoff — Module 3 (CRM & Seeding), Step 4: Results, Analytics Feeds, Documents & Tasks
  Written by the IMPLEMENTATION side (multi-agent build orchestrated by claude-fable-5).
  Consumed by a SEPARATE review model.
  Build spec: reviews/SPEC-module3-step4-results-docs-tasks.md
-->

# Deep Review — Module 3 Step 4: Results (EMV/CPE/CPM), FACT Loaders & Dashboards, Documents & Tasks

- **review_status:** REVIEWED (2026-07-07, adversarial multi-agent pass — see `reviews/REVIEW-module3-FINDINGS-2026-07-07.md`)
- **outcome:** ACCEPT WITH FIXES. Step-4 findings: C2 (fact_mention late-attribution undercount) BLOCKS; H1 (fabricated engagement zero), M2 (product double-count), M4 (EMV disclosure), M5/M6 (ADR-0016 docs), L2-L4, GAP-3/4.
- **implemented_by:** claude-fable-5 (orchestrated multi-agent build: foundation / governance / analytics / results-UI / documents-tasks; final green bar verified by the orchestrator)
- **implementation_date:** 2026-07-06 → 2026-07-07
- **reviewer:** unassigned
- **deep_review_trigger:** money-adjacent KPI computation (EMV/CPE/CPM), a **gated exception carved into the P0 append-only trigger** (fact_shipment restamp), first file-upload surface (documents), new authz surfaces (documents/tasks/results/dashboard routes + panels), canonical-doc edits (ADR-0016), cross-module analytics reads — major feature step closing Module 3.

> [!IMPORTANT]
> **TODO — MANDATORY INDEPENDENT DEEP REVIEW (deferred, not yet run).**
> Per the product owner's instruction (2026-07-06), the multi-agent adversarial
> (Ultracode) review workflow was **intentionally DEFERRED** for Step 4 — to be
> run later as its own task. NO independent review of any kind has happened on
> this diff — no canon-fidelity sweep, no ownership sweep, no security sweep,
> no correctness sweep, no test-adequacy sweep. This step MUST NOT be treated
> as accepted until that review works this file end to end (checklist §6 +
> scenarios §7). **Steps 1, 2 AND 3 reviews are ALSO still pending**
> (reviews/REVIEW-module3-*-2026-07-06.md) — Step 4 builds directly on all of
> them; review them first or together. All Module 3 work through Step 4 is
> uncommitted in git.

---

## 1. Implementation summary

Built Step 4 per `reviews/SPEC-module3-step4-results-docs-tasks.md` (spec authored same session), closing Module 3:

- **Spend input (D1):** new nullable jsonb `spend` on `campaigns` + `seeding_campaigns` (MetricValue envelope, tier CONFIRMED, metric `'spend'` — products.unit_value precedent), with form fields in both indexes. Canon presumes "agency-entered spend" (00-data-model.md:587) but defines no field — flagged deviation.
- **Analytics feeds (REQ-M3-009/013 plumbing):** `loadShipmentFacts()` + `loadSeedingContentFacts()` now live in `NeonAnalyticsService` (dispatched shipments only; `date_key` per canon DIM-Date doctrine with a documented fallback chain; PUBLIC metrics via `qds_public_metric()`; EMV via the established latest-`emv_results` lateral join; `estimated_reach` NULL — DEF-003). `dim_seeding_campaign` upsert activated; date dimension extended over the new facts. Two **additive non-canonical rollups** (D3/D5): `rollup_mention_by_campaign` (campaign half of REQ-M3-009) and `rollup_seeding_by_product_slice` (platform/content-type/country slices for AC-M3-019). Five new `RollupReader` methods. **Append-only tension resolved with a gated restamp** (see §4 — the loudest flag).
- **Results UI (AC-M3-014/015, results AC-M3-018):** `CampaignResultsPanel` + `SeedingResultsPanel` on the detail pages — every number from `RollupReader` (ADR-0010); tier badges per DP-001; CPE = spend/engagement and CPM = spend/(views÷1000) computed display-time as DERIVED (D4), "unavailable" (with specific reasons) on missing spend or NULL/0 divisors — never zero, never ∞; EMV ESTIMATED with the model · formula · rate-card · rates disclosure line via `EmvCalculator::activeConfiguration()`; estimated + true unique reach render "unavailable" citing DEF-003.
- **Product aggregation dashboard (AC-M3-019):** `/crm/results` — cross-influencer product totals (shipments, posted, post_rate DERIVED, creators_reached, content, views, reach, engagement, EMV) with grain/from/to/brand/product filters plus platform/content-type/country slice selectors (slice view = content-side measures, captioned). Landing card replaced with a live link.
- **Seeding-results export:** `ReportBuilder::SEEDING_RESULTS` (product + shipment-detail sections, tier-suffixed columns, disclosures incl. CPE/CPM formulas, DEF-003 note, EMV model line). `ExportsIndex` gained a report selector (kind list was hardcoded).
- **Documents (AC-M3-016):** reusable `DocumentsPanel` on creator profile / campaign detail / seeding detail (exactly-one-parent mount), private-disk upload (10 MB + extension allowlist — D7), **signed** download route + `DocumentDownloadController`, transactional delete (blob+row), `document.uploaded/downloaded/deleted` audits. New `document_attachments.seeding_campaign_id` FK (D6).
- **Tasks (AC-M3-017):** `/crm/tasks` `TasksIndex` (filters, Overdue/Due-soon sections, CRUD, `task.status_changed` audits) + compact `TasksPanel` (creator profile, campaign detail) + landing card with open count. `qds:send-task-reminders` command (hourly, window `qds.tasks.reminder_window_hours` = 48): stamps new `tasks.reminder_sent_at` (D8) exactly once + audits `task.reminder_fired`; channel is in-app (D9 — no email/push in the frozen stack).
- **Governance:** **ADR-0016 — "No external client access in v1 — CLIENT_VIEWER surface dropped"** (product-owner decision 2026-07-06) written into the decision log (ledger row + count bump to sixteen) and propagated to module-3 spec (5 touchpoints), req-matrix, modules-overview, roadmap (P3 + P4 white-label). CLIENT_VIEWER remains a defined deny-everything role; no client login/report-approval surface ships; REQ-M3-012's remaining substance (ADMIN-only User/Role writes) had already shipped.

**IN:** all of the above + 88 new tests. **OUT (per spec §5):** CLIENT_VIEWER login & report approval (ADR-0016), courier APIs, CONFIRMED reach (DEF-003/004), email/push channels, ReportFilters product filter (flagged future), canonical amendments beyond ADR-0016's cross-refs.

## 2. Changed files

**Spec (working artifact):** `reviews/SPEC-module3-step4-results-docs-tasks.md` — NEW (decisions D1–D9).

**Migrations**
- `database/migrations/2026_07_06_100001_add_spend_to_campaigns_and_seeding_campaigns.php` — D1.
- `database/migrations/2026_07_06_100002_add_seeding_campaign_id_to_document_attachments.php` — D6 (restrict-on-delete: seeding runs with documents now refuse deletion; UI copy updated).
- `database/migrations/2026_07_06_100003_add_reminder_sent_at_to_tasks.php` — D8.
- `database/migrations/analytics/2026_07_06_110001_create_campaign_and_slice_rollups.php` — the two additive rollups (D3/D5).
- `database/migrations/analytics/2026_07_06_110002_allow_gated_fact_shipment_restamp.php` — **modifies the P0 `qds_analytics_append_only()` trigger function** (see §4).

**Analytics (SVC-Analytics):** `app/Platform/Analytics/NeonAnalyticsService.php` (two loaders, dim_seeding_campaign upsert, date-dim extension, header comment), `RollupReader.php` (five seeding/campaign readers).

**CRM — results UI:** `app/Modules/CRM/Livewire/Results/{CampaignResultsPanel,SeedingResultsPanel,SeedingResultsDashboard}.php` + `resources/views/livewire/crm/{campaign-results,seeding-results,seeding-results-dashboard}.blade.php` + `resources/views/crm/results.blade.php` (NEW page).

**CRM — documents & tasks:** `app/Modules/CRM/Livewire/Documents/DocumentsPanel.php`, `Livewire/Tasks/{TasksIndex,TasksPanel}.php` + `Tasks/Concerns/PresentsTaskStatus.php`, `Http/Controllers/DocumentDownloadController.php`, `Console/SendTaskRemindersCommand.php`, blades `documents-panel/tasks-index/tasks-panel` + `resources/views/crm/tasks.blade.php` (NEW page).

**CRM — shared:** `routes.php` (crm.results, crm.tasks.index, signed crm.documents.download), `CrmServiceProvider.php` (6 component + 1 command registrations), Models `Campaign/SeedingCampaign/DocumentAttachment/Task`, factories ×4, `Livewire/Campaigns/CampaignsIndex.php` + `Seeding/SeedingCampaignsIndex.php` (spend field), wrappers `campaign-detail/seeding-detail/creator-profile/index` blades (panel mounts, Results + Tasks landing cards).

**Export/Monitoring:** `app/Platform/Export/ReportBuilder.php` (SEEDING_RESULTS), `app/Modules/Monitoring/Livewire/Exports/ExportsIndex.php` + `resources/views/livewire/monitoring/exports-index.blade.php` (report select; pre-existing `x-form.error` crash fixed — see §4).

**Config/schedule:** `config/qds.php` (`documents` + `tasks` blocks), `routes/console.php` (hourly reminders, flagged cadence).

**Docs (governance):** `docs/05-decisions/decision-log.md` (ADR-0016), `docs/50-modules/module-3-crm-seeding.md`, `docs/90-traceability/00-req-matrix.md`, `docs/10-product/01-modules-overview.md`, `docs/80-delivery/00-roadmap.md`.

**Tests (88 new):** `tests/Feature/Analytics/{SeedingAnalyticsTest,SeedingRollupReaderTest}.php`, `tests/Feature/Crm/{CampaignResultsPanelTest,SeedingResultsPanelTest,SeedingResultsDashboardTest,DocumentsPanelTest,TasksIndexTest,TasksPanelTest,TaskRemindersCommandTest}.php`, extensions to `CampaignsCrudTest/SeedingCampaignsCrudTest/CrmSchemaTest/CrmEnvelopeIntegrityTest/CrmModelRelationshipsTest` and `tests/Feature/Export/ExportFlowTest.php`.

## 3. Canonical documents relied upon

- `docs/50-modules/module-3-crm-seeding.md` — §2.8 REQ-M3-009 + AC-M3-014/015/018(results), §2.9 REQ-M3-010/011 + AC-M3-016/017, §2.11 REQ-M3-013 + AC-M3-019(product), §2.10 REQ-M3-012 (ADR-0016 target).
- `docs/30-data-model/01-analytics-model.md` — FACT-Shipment/FACT-SeedingContent grains+measures+dims (§4), rollup catalog + tier-aware aggregation doctrine (§5, :43/:53-54/:131-133), worked example §6.
- `docs/30-data-model/00-data-model.md` — ENT shapes (Campaign/SeedingCampaign/Shipment/DocumentAttachment/Task/Product), MET catalog (:575-585 — EMV "model + rates shown", ESTIMATED), the spend prose + weakest-input tier rule (:587).
- `docs/00-meta/03-glossary.md` — ENUM-TaskStatus, ENUM-MetricTier (+F7), ENUM-ExportFormat, ENUM-RoleName.
- `docs/20-cross-cutting/01-deferred-register.md` — DEF-003 + unavailable-never-empty; `00-data-principles.md` — DP-001/002/003/005.
- `docs/05-decisions/decision-log.md` — ADR-0010 (dashboards read rollups only), ADR-0012 (hand-built Livewire), ADR-0014 (feature-removal precedent for ADR-0016), ADR-0015 format template.
- `docs/80-delivery/00-roadmap.md` — P3 exit criteria (results line + documents/tasks/roles line).

## 4. Known deviations / open conflicts

**Schema/canon deviations (ledger class — awaiting doc amendments, like Steps 1–3's):**
- **D1** `campaigns.spend` + `seeding_campaigns.spend` — no canonical ENT field (only the :587 prose). Spend written WITH the `'spend'` metric label (the label itself is an already-flagged MetricValue deviation class).
- **D3/D5** `rollup_mention_by_campaign` + `rollup_seeding_by_product_slice` — the analytics-model §5 rollup catalog is canonical and doesn't list them; both additive; the canonical P0 views are untouched. Slice-view semantics deviations: `creators_reached` counts creators who **posted** within the slice (not shipped-to); `country` comes from the highest-confidence `dim_geo` row per creator and is **NULL until Module 2 ships** → country slices render unavailable, never zero.
- **D6** `document_attachments.seeding_campaign_id` — ENT shape lists creator/campaign only; module-3 §2.9 prose + AC-M3-016 name seeding runs. Restrict-on-delete: seeding runs with documents refuse deletion (delete-refusal copy updated).
- **D8** `tasks.reminder_sent_at` — not in the ENT shape; needed for fire-exactly-once.

**LOUDEST FLAG — gated append-only exception (reviewer MUST rule on this):** `loadMentionFacts()`'s actual P0 mechanism is id-watermark + stamp-once + INSERT-only, with **no** re-materialization of mutated sources. Copying that verbatim to `fact_shipment` would freeze `posted=0` forever for any shipment materialized before its creator posts — permanently corrupting posted_count/post_rate in ALL canonical seeding rollups and contradicting analytics-model §6 step 2 ("FACT-Shipment.posted flips"). Since UNIQUE(shipment_id, date_key) + the rollups' one-row-per-shipment shape make accumulation rows impossible, migration `2026_07_06_110002` CREATE-OR-REPLACEs `qds_analytics_append_only()` to allow **DELETE (never UPDATE) on fact_shipment only** while the transaction-local GUC `qds.analytics_shipment_restamp='on'` (set/cleared by `loadShipmentFacts()` around its purge). Every other op on every fact table still raises; regression-tested. This modifies P0-owned trigger semantics.
- **Consequence accepted on the mention side:** `fact_mention` keeps stamp-once, so mentions that gain `campaign_id` AFTER their fact load (the hourly XMC-002 linker races the 30-min refresh) **never reach `rollup_mention_by_campaign`** → campaign results can undercount late-attributed mentions. Extending the restamp gate to fact_mention was deliberately NOT done (would rework a reviewed P1 loader). **Reviewer to decide** whether fact_mention needs the same treatment.
- Other accepted staleness (same class as P0): unlinked content's already-loaded `fact_seeding_content` rows keep counting; hard-deleted OLTP shipments leave orphaned fact rows.

**Analytics implementation notes:** `fact_shipment` watermark stores a µs-epoch of `shipments.updated_at` in the shared `last_id` bigint (semantic overload, documented) compared **inclusively** (second-precision timestamps) — boundary-second shipments re-stamp idempotently each refresh and re-count in `facts_loaded` telemetry. `fact_seeding_content` uses a two-source watermark pair (snapshots.id + pivot.id) so late links backfill old snapshots and vice versa; relinks after unlink are conflict-skipped. `date_key` fallback chain (canon silent): `coalesce(content.published_at, shipment.posted_at, snapshot.captured_at)::date`. Engagement honesty diverges deliberately between the two new rollups: mention-by-campaign yields NULL when no component was observed (story-only mentions); the slice view mirrors the canonical coalesce-sum so slices reconcile with the frozen unsliced view.

**Results/export interpretation flags:** per-creator rows in the seeding panel are a display-time regroup of ROLLUP-SeedingByShipment rows (no per-creator reader exists; possible double-count if one content item were linked to two shipments of the same creator in one run — a future `RollupReader::seedingByCreator()` is the clean fix). Campaign CPM uses mention-side views; seeding CPM uses seeding-content views (each panel its own canonical aggregate — a campaign tracked only via shipments shows campaign-level CPE/CPM unavailable while its run panel has them). Export tier suffixes label operational counts (Shipments/Posted/Creators reached) as [CONFIRMED] — an interpretation, not canon. Export filters: creator_id applies only to the shipment-detail section, grain only to the product section (documented in code). EMV amounts render without a currency suffix (fact_seeding_content/rollups carry no currency column); currency is disclosed in the adjacent EMV model line. Dashboard tables are unpaginated (rollup row volumes small today; flagged).

**Repairs to pre-existing defects made in passing:** (a) `exports-index.blade.php` used `<x-form.error name=…>` (component prop is `for=`) — any render of the exports page threw; all seven fixed while adding the report select. (b) The first documents/tasks build attempt used nullable class-typed `mount()` params — Laravel DI materializes EMPTY models for absent class-typed params, so those panel mounts always threw; rewritten to parameterless `mount()` validating Livewire-assigned public props. (c) `users.name` referenced where only `display_name` exists — fixed in tasks surfaces. (d) `DocumentsPanel` blob-delete hardening: `putFile()===false` now throws; a failed-but-still-present blob delete inside the delete transaction throws so the row delete rolls back.

**Governance inventory:** CLIENT_VIEWER/white-label mentions deliberately LEFT unamended (out of assigned touchpoints): glossary ENUM-RoleName value row (role stays canonical), data-model ENT-Role/ENT-Client notes, **vision-and-scope.md:21 ("only externally-facing surface" — now contradicted by ADR-0016, amendment candidate)**, system-architecture.md:186 report-API behaviour, module-1-monitoring.md :355/:359, roadmap :348/:389/:407, req-matrix :52 P4 mermaid label. Pre-existing **AC-ID collision**: module-3 defines AC-M3-018/019 twice (results §:280/:284 and roles §:296/:298) — not renumbered.

**Other:** `document.downloaded` audit event added beyond the spec's uploaded/deleted pair (mirrors export.downloaded). Landing-page open-task count computed in a small `@php` block (Route::view page, no controller). Pre-existing flake seen once: `CampaignCreatorsPanelTest::test_a_restricted_creator_is_blocked_before_commit` (BrandRestrictionGuard case-insensitive name match; passes in isolation/re-runs; did not reproduce in later full runs) — worth a separate look.

## 5. Tests & checks

- **Executed (passing) — final green bar (run by the orchestrator after all agents):**
  - `XDEBUG_MODE=off php artisan test` — **607 passed (2,507 assertions)**; 88 new since Step 3's 519; full-suite regression clean.
  - `XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=1G` — level 6, **no errors** (315 files).
  - `XDEBUG_MODE=off vendor/bin/pint --test` — **passed**.
- **Per-area (agent runs, all passing):** analytics 21/184 incl. loader idempotency, restamp, watermark pair, rollup shapes; results+dashboard 35/105; exports 9/52; documents+tasks 31/146 incl. signed-download rejections and reminder exactly-once.
- **Executed (failing/skipped):** none at handoff. One transient failure of the pre-existing `CampaignCreatorsPanelTest` flake during an intermediate run (§4); green on the final bar.
- **Not executed:** the adversarial (Ultracode) review workflow — **deferred by product-owner instruction, see the TODO banner**; manual browser walkthrough (feature tests render pages via HTTP + Livewire harness); docs linter (spec-only — no runnable script exists; conventions followed manually).
- **External integrations not verified:** none — no external sources touched; documents use the local private disk.

---

## 6. Review checklist

> Reviewer: mark `[x]` only after you have **verified** the item. Add a note per item.

### 6.1 Canonical fidelity
- [ ] Results/rollup semantics match analytics-model §4–6 doctrine (grains, tier-aware aggregation, never-sum-DERIVED).
- [ ] AC-M3-014/015/016/017/018/019 satisfied as implemented; no canonical fact silently changed.
- [ ] ADR-0016 text + propagation faithful to the product-owner decision; left-mention inventory (§4) acceptable.

### 6.2 Architecture & ownership
- [ ] Dashboards/exports read ROLLUP-* only (ADR-0010) — no FACT/OLTP aggregation leaked into UI.
- [ ] Loaders respect module boundaries (SVC-Analytics reads, never writes OLTP).
- [ ] The fact_shipment restamp gate (§4) is an acceptable resolution of append-only vs posted-flips.

### 6.3 Security & personal data
- [ ] Documents: upload validation, private disk, signed download + authz (incl. CLIENT_VIEWER-with-valid-signature rejection), no path traversal, no personal data in the star schema (DP-005).
- [ ] Every new route/component authorizes in mount AND every mutator; direct-mutator bypasses tested.

### 6.4 Correctness
- [ ] CPE/CPM math + unavailable states (missing spend, NULL/0 divisors); EMV disclosure correctness.
- [ ] Loader edge cases: watermark boundaries, posted-flip restamp, late links, unlink/relink, date_key fallbacks.
- [ ] Reminder exactly-once under window/status permutations.

### 6.5 Migrations & database
- [ ] Five new migrations reversible/safe; the trigger CREATE-OR-REPLACE's down() restores P0 semantics exactly.
- [ ] New rollup indexes/uniqueness correct; restrict-FK consequences (D6) acceptable.

### 6.6 Test adequacy
- [ ] 88 new tests cover failure modes and boundaries, not just happy paths (esp. §4 items).
- [ ] fact_mention late-attribution undercount: decide accept vs extend the restamp gate (needs a test either way).

### 6.7 Adversarial verification
- [ ] Each finding independently verified (not taken on first read).

## 7. Suggested review scenarios — where to focus

1. **The restamp gate** — *Focus:* migration `2026_07_06_110002`, `loadShipmentFacts()`. *Do:* attempt DELETE/UPDATE on every fact table with and without the GUC from outside the loader; verify the GUC is transaction-local (no session leakage in the pool); verify down() restores P0. *Risk:* the append-only guarantee of the whole star schema.
2. **Late-attribution undercount** — *Focus:* `loadMentionFacts()` stamp-once vs the hourly XMC-002 linker. *Do:* mention loaded → linker sets campaign_id → refresh → confirm the mention never reaches rollup_mention_by_campaign; judge materiality for AC-M3-014 honesty; decide the fix path. *Risk:* campaign results systematically understate.
3. **CPE/CPM + tier honesty end-to-end** — *Focus:* both panels, dashboard, export disclosures. *Do:* spend absent/zero/set × engagement/views NULL/0/positive; verify DERIVED badges, weakest-input reasoning, no zero-rendered unavailables, panel-vs-export consistency. *Risk:* AC-M3-015 numbers presented dishonestly.
4. **Documents attack surface** — *Focus:* `DocumentsPanel`, `DocumentDownloadController`. *Do:* double extensions / spoofed MIME / oversized files; signed-URL tampering + expired signatures; cross-parent access (document of campaign A via creator B's panel); blob orphaning on failed transactions. *Risk:* first file-upload surface in the product.
5. **Slice semantics vs AC-M3-019** — *Focus:* `rollup_seeding_by_product_slice`, dashboard slice mode. *Do:* verify sliced content totals reconcile with the unsliced canonical view; creators_reached=posters caption is honest; country renders unavailable (not zero/empty) pre-M2. *Risk:* "any grain and slice" delivered misleadingly.
6. **Reminder mechanics** — *Focus:* `SendTaskRemindersCommand`. *Do:* window boundary (due_at exactly now+48h), status flips after firing, DONE/CANCELLED exclusion, repeat runs, concurrent execution. *Risk:* AC-M3-017 fires never/twice.
7. **Reconciliation across surfaces** — *Focus:* seeding panel per-creator regroup vs rollup_seeding_by_creator_campaign; product totals vs shipment rows vs export sections. *Do:* multi-shipment/multi-creator fixtures; hunt the double-count edge (§4). *Risk:* same question, different numbers on different screens.

## 8. High-risk areas

- `app/Platform/Analytics/NeonAnalyticsService.php` — two new loaders + the restamp purge; feeds every seeding number in the product.
- `database/migrations/analytics/2026_07_06_110002_*.php` — rewrites a P0 trigger function.
- `app/Modules/CRM/Livewire/Documents/DocumentsPanel.php` + `Http/Controllers/DocumentDownloadController.php` — upload/download security.
- `app/Platform/Analytics/RollupReader.php` — five new read paths every results surface trusts.
- `app/Platform/Export/ReportBuilder.php::seedingResults` — externally-shared artifact of the same numbers.

---

## 9. Reviewer findings

> Filled by the REVIEW model. One checkbox per finding; check when resolved/dispositioned.

- [ ] —

## 10. Review sign-off

- **Reviewer:** —
- **review_status → REVIEWED on:** —
- **outcome:** —
- **Summary:** —
