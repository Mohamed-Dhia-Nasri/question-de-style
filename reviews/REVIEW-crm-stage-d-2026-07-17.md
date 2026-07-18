# Review handoff — CRM Stage D (data-model & lifecycle hardening)

Branch `feat/crm-ux-stage-d`, Stage D commits `a8944b8..bfe27d6` (13 = 11 plan tasks + 1 pre-sweep fix + this docs commit). Full suite **1190/0** at the sweep (baseline was 1124 on main). Plan: `docs/superpowers/plans/2026-07-17-crm-stage-d-hardening.md`. Audit source: `docs/superpowers/specs/2026-07-16-crm-ux-redesign-audit-and-plan.md` §Stage D + §2.5/§2.6. Release note: ADR-0027 in `docs/05-decisions/decision-log.md`.

Already done: per-task spec+quality reviews (all Approved; every one verified independently against source, not just the report), per-task TDD RED→GREEN, running ledger `.superpowers/sdd/progress.md`. **Item 3 (F03 repair) was already shipped in Stage A** and was verified-not-rebuilt. Still owed: this fresh whole-branch adversarial pass + live browser verification.

## What shipped (one line per task)

- **D1** — Additive schema: `tasks.seeding_campaign_id` + `communication_logs.seeding_campaign_id` (nullable FK + composite `(col, tenant_id) → seeding_campaigns(id, tenant_id)`), `campaigns.objective` (text) + `campaigns.markets` (jsonb, cast `array`); models/factories/relations; `StageDSchemaTest` proves the composite FK blocks a cross-tenant anchor.
- **D2** — Tasks can live on a seeding run: `TasksPanel` third parent (mirrors `DocumentsPanel`), mounted on the run's Docs & Tasks tab; `TasksIndex` board gains a seeding scope.
- **D3** — Communication log gains optional Campaign + Seeding-run context selects on the creator form (also fixes the dead `campaign_id` column).
- **D4** — Campaign brief: `objective` + `markets` edited in the edit modal (edit-only, like spend), shown read-only on the Overview.
- **D5** — `CampaignWriter` service (create/update) with a brand-coherence guard: changing a campaign's brand while it has seeding runs throws `CampaignBrandLocked` → validation error; both the edit form and the wizard write through it; `status_changed` audit preserved verbatim; wizard's single transaction intact.
- **D6** — Alias-aware restriction matching: `BrandRestrictionGuard` folds name + aliases in one shared needle path for `assertNotRestricted` and `restrictedCreatorIds(Brand)`; `restrictedCreatorIdsForName(string)` stays name-only.
- **D7** — BLOCKLISTED enforcement across all four attach paths (`blocklistedCreatorIds`): soft-skip in bulk (picker/copy-roster/wizard), hard `ValidationException` for a shipment recipient (before the transaction). Two old "blocklisted-selectable" tests rewritten.
- **D7fix** — Wizard now flags blocklisted creators at step 4 and labels the Done-screen skipped list accurately (not "no-go list includes this brand").
- **D8** — Adding a restriction warns which existing rosters already include that creator (name+alias fold); never auto-detaches; the preference saves regardless; no false nag when re-saving unchanged restrictions.
- **D9** — Read-only live seeding progress strip (roster/shipped/delivered/posted) from the Shipment table via constrained `loadCount`; never writes `posted`.
- **D10** — Campaign + seeding one-click suggestion banners (`CampaignStatusActions`, `SeedingStatusActions`): at most one prompt, `wire:confirm`, re-checks the trigger before acting (stale = no-op), `crm.manage` on the write, audits `campaign.status_changed` / `seeding_campaign.status_changed`.
- **D11** — Shipment date→status one-tap hint; outbound-comms → "Mark as Contacted?" suggestion for a new contact (`crm.manage` on `markContacted`).
- **D12** — ADR-0027 (release note) + this handoff.

## For a fresh adversarial pass (checkboxes)

- [ ] **Migration composite-tenant-FK correctness + rollback.** `2026_07_18_100001` adds `(seeding_campaign_id, tenant_id) → seeding_campaigns(id, tenant_id)` on tasks AND communication_logs; confirm `down()` drops the composite constraint before `dropConstrainedForeignId`, and that a foreign-tenant anchor is genuinely blocked by the composite FK (not just the tenant global scope — `StageDSchemaTest::test_seeding_anchor_is_tenant_scoped` asserts a `QueryException`). `2026_07_18_100002` is columns-only.
- [ ] **CampaignWriter guard completeness across BOTH write paths.** `updateCampaign` fires the guard only on a real brand change with runs; `createCampaign` opens no transaction (composes inside the wizard's) so a mid-wizard failure still rolls the campaign back. Verify the `campaign.status_changed`/`campaign.created`/`campaign.updated` audit events are byte-identical to the pre-Stage-D shapes (no lost/duplicated event) and that a future third edit path could not bypass the guard.
- [ ] **Alias-matcher parity + name-only carve-out.** `assertNotRestricted` and `restrictedCreatorIds(Brand)` route through the same `needlesForBrand`/`restrictedCreatorIdsForNeedles` (can't diverge); `restrictedCreatorIdsForName(string)` never touches the `Brand` model, so no alias leaks into the wizard's typed-name path. Multibyte fold stays PHP-side. (Degenerate brand names `"0"`/empty are a known spec-inherited edge — low value.)
- [ ] **BLOCKLISTED enforcement across all four sites + hard/soft asymmetry.** `ManagesCreatorRoster::attachSelected`, `SeedingCreatorsPanel::copyCampaignRoster`, `CampaignWizard::commit` soft-skip; `ShipmentsPanel` auto-attach hard-blocks BEFORE the `DB::transaction` (no phantom attach). A both-restricted-and-blocklisted creator is reported once. The per-creator `assertNotRestricted` belt-and-suspenders fallback is kept.
- [ ] **Re-check-on-add warning.** Never auto-detaches; saves the preference regardless; `newlyAddedFoldedNames` diff means re-saving the same list (or case/whitespace variants) does not nag; the roster-match query cost is bounded (one `with('brand')` load of the creator's campaigns + seeding runs).
- [ ] **Progress strip.** Live from the Shipment table, not rollups; the OR-precedence in the constrained `loadCount` closures is correctly grouped (a Delivered shipment counts in both shipped and delivered); divide-by-zero guarded; strip hidden when `shipments_count === 0`; never writes `posted`/`posted_at`.
- [ ] **Suggestion banners stale-state + authorize.** `applyStatus` re-authorizes `update` (not just mount `view`) and recomputes `suggestion()` — a status changed underneath (or a replayed `wire:click`) is a silent no-op, and no client-supplied target is trusted. Zero-shipment run does not vacuously satisfy "all delivered". `markContacted` authorizes `update`; note it has no idempotency guard (same `crm.manage` already grants full status control — low risk).
- [ ] **New anchors are tenant-scoped end to end.** Tasks/comms seeding selects validate with `TenantRule::exists('seeding_campaigns','id')`; the board and the comms form reject a foreign-tenant id like a nonexistent one.
- [ ] **The two rewritten blocklist tests + the two added-vs-rewritten seeding twin** genuinely assert skip-at-attach (still selectable in the picker, not on the roster).

## Deferred / not in Stage D (awareness, NOT defects)

- **Item 3 (F03)** shipped in Stage A — verified, untouched.
- **Pre-existing gaps (unchanged here):** `CommunicationLogPanel` and `BrandPreferencesPanel` writes record no audit event (task/campaign writes do) — a slight inconsistency to close in a later hardening pass.
- **Shipment date→status hint** is practically an edit-time reconciliation aid: the Stage C progressive form (F28) clears date fields when status drops below their reveal threshold, so the "date ahead of status" state is only reachable on an already-inconsistent DB row (import/seed/legacy), not live create-time entry. This is an accepted tension between §2.5's suggestion and the F28 progressive form; enforcement/correctness is unaffected.
- **Wizard copy:** the Done-screen heading and step-4 blocklist flag were corrected (D7fix); two remaining step-4/step-5 subtitles still speak only of brand no-go lists (incomplete but not wrong — restricted creators are flagged as described). Consider generalizing in a copy pass.
- **Minor test-coverage gaps** (all verified-correct-by-inspection): no explicit create-tamper regression for campaign objective/markets (D4); the progress-strip `expected_posts_count ?: shipments_count` fallback branch isn't independently pinned (D9); no explicit "re-save same restriction doesn't nag" test (D8, manually verified).

## Merge coordination

Stage D edits `seeding-detail`/`campaign-detail`/`campaigns-index`/`campaign-wizard` blades and `ShipmentsPanel`/`CampaignWizard`/`ManagesCreatorRoster`/`BrandRestrictionGuard` — all already on main from Stage C, no external branch in flight. Migrations `2026_07_18_*` were applied forward-only on the dev DB (never `migrate:fresh`).
