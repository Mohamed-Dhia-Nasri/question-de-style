<!--
  Deep-Review Handoff — Module 3 (CRM & Seeding), Step 3: Campaigns, Seeding, Shipments & Content Matching
  Written by the IMPLEMENTATION model. Consumed by a SEPARATE review model.
  Build spec: reviews/SPEC-module3-step3-campaigns-seeding.md
-->

# Deep Review — Module 3 Step 3: Campaigns, Seeding, Shipments & Content Matching

- **review_status:** REVIEWED (2026-07-07, adversarial multi-agent pass — see `reviews/REVIEW-module3-FINDINGS-2026-07-07.md`)
- **outcome:** ACCEPT WITH FIXES. Step-3 findings: C1 (confirm() sibling-mention leak) BLOCKS; M1 (parent-campaign brand coherence), M3 (unlink over-retracts), plus matching scale GAP-2.
- **implemented_by:** claude-fable-5
- **implementation_date:** 2026-07-06
- **reviewer:** unassigned
- **deep_review_trigger:** canonical architecture (XMC-002, SeedingEvidenceSource rebind, matching in SVC-EnrichmentAI), authorization changes (8 new Livewire surfaces + 7 routes), cross-module writes (M3↔M1 contracts) — major feature step

> [!IMPORTANT]
> **TODO — MANDATORY INDEPENDENT DEEP REVIEW (not yet run).**
> Per the product owner's instruction (2026-07-06), the in-session multi-agent
> adversarial review workflow that Step 2 received was **intentionally skipped**
> for Step 3. NO independent review of any kind has happened on this diff —
> no canon-fidelity sweep, no ownership sweep, no security sweep, no
> correctness sweep, no test-adequacy sweep. This step MUST NOT be treated as
> accepted until a separate review model works this file end to end (checklist
> §6 + scenarios §7). Step 2's review (reviews/REVIEW-module3-identity-merge-
> 2026-07-06.md) is ALSO still pending — review it first or together; Step 3
> builds directly on it.

---

## 1. Implementation summary

Built Step 3 per `reviews/SPEC-module3-step3-campaigns-seeding.md` (spec authored same session): master-data CRUD (Clients, Brands incl. alias lists, Products incl. CONFIRMED-tier unit values), campaign management (AC-M3-009: closed-set status + audited from→to transitions), seeding campaigns (AC-M3-010: the four SeedingType variants; product-brand coherence), shipments (AC-M3-012: manual closed-set status updates + audited transitions; recipients restricted to the run's attached creators), the **AC-M3-007 brand-restriction hard filter** on campaign/seeding joins, and **REQ-M3-008 content-to-campaign matching**. Matching reuses the reviewed P1 engine rather than inventing one: M3 now implements the pre-existing `SeedingEvidenceSource` seam (dispatched shipments become attribution evidence → the P1 `MentionClassifier` produces SEEDED mentions with `shipment-record:{id}` proving signals — AC-M1-020 is live end-to-end), and a new platform-layer `SeededContentLinker` materializes the links: M3 rows via the new `ShipmentContentLinker` contract, the M1-owned `mentions.campaign_id` via the new **XMC-002 `ContentMatchFeedback`** contract. Auto-linking uses the codebase's established review cut-point (AI_ASSESSED @ HIGH/MEDIUM; LOW/UNKNOWN stays in the existing shared review queue — AC-M3-013); the operator's manual "Link content"/"Remove link" on the shipments panel is the human confirm/deny half. **IN:** all of the above + `qds:link-seeded-content` command (config-gated, scheduled hourly) + tests. **OUT:** results/EMV/CPE/CPM, FACT-Shipment/FACT-SeedingContent loaders (declared Step 4), documents/tasks, CLIENT_VIEWER (needs its ADR first), courier APIs, any schema change (none — zero migrations).

## 2. Changed files

**Spec (working artifact)**
- `reviews/SPEC-module3-step3-campaigns-seeding.md` — NEW: Step 3 build spec incl. decisions D1–D6.

**Matching — platform layer (SVC-EnrichmentAI)**
- `app/Platform/Enrichment/Attribution/ShipmentEvidence.php` — additive: `REFERENCE_PREFIX` + `referenceFor()`/`shipmentIdFrom()` (the signal grammar both sides share). No behavior change to P1 code.
- `app/Platform/Enrichment/Matching/SeededContentLinker.php` — NEW: scans SEEDED content-item mentions; auto path AI_ASSESSED@HIGH/MEDIUM, human path HUMAN_REVIEWED/HUMAN_CORRECTED/CONFIRMED; parses shipment references from signals (never guesses — human-blessed mentions without refs are counted and reported); campaign attribution only when exactly one distinct parent campaign.
- `app/Platform/Enrichment/Matching/ShipmentContentLink.php`, `LinkSummary.php` — NEW DTOs.
- `app/Platform/Enrichment/Contracts/ShipmentContentLinker.php` — NEW cross-module write contract (M3-owned rows; PlatformAccountProfileSync pattern).
- `app/Platform/Enrichment/Matching/Console/LinkSeededContentCommand.php` — NEW `qds:link-seeded-content`, self-gating on `qds.matching.enabled`.
- `app/Platform/PlatformServiceProvider.php` — `SeedingEvidenceSource` rebound Null→`ShipmentEvidenceSource` (M3); `ShipmentContentLinker`→`ShipmentContentWriter` (M3); command registered.

**Matching — M3 side (SVC-CRM)**
- `app/Modules/CRM/Services/ShipmentEvidenceSource.php` — NEW: dispatched shipments (`shipped_at` NOT NULL — spec D1) → `ShipmentEvidence` with brand/product/campaign context.
- `app/Modules/CRM/Services/ShipmentContentWriter.php` — NEW: idempotent pivot attach + posted/postedAt lifecycle (postedAt = earliest linked publish time, recomputed on unlink — spec D6); recipient re-verified against the content's creator.

**Matching — M1 side (XMC-002)**
- `app/Modules/Monitoring/Contracts/ContentMatchFeedback.php` — NEW: XMC-002 contract (module-3 §5), owner-side like XMC-001.
- `app/Modules/Monitoring/Services/ContentMatchFeedbackRecorder.php` — NEW: the ONLY writer of `mentions.campaign_id`; confirm fills only unattributed mentions, deny retracts exactly the named campaign, different existing attributions never clobbered; audit-logged; the classification envelope is never touched.
- `app/Modules/Monitoring/MonitoringServiceProvider.php` — register() added with the XMC-002 binding.

**CRM services / exceptions**
- `app/Modules/CRM/Services/BrandRestrictionGuard.php` + `app/Modules/CRM/Exceptions/BrandRestrictionViolation.php` — NEW: AC-M3-007 hard filter (case-insensitive restricted-brand NAME match — spec D4).

**UI (8 new Livewire components + views, UsersIndex pattern)**
- `app/Modules/CRM/Livewire/Clients/ClientsIndex.php`, `Brands/BrandsIndex.php`, `Products/ProductsIndex.php` — master data CRUD; restrict-FK deletes surfaced as friendly errors (savepoint-wrapped).
- `app/Modules/CRM/Livewire/Campaigns/CampaignsIndex.php` (+status-transition audit), `Campaigns/CampaignCreatorsPanel.php` (hard filter on attach).
- `app/Modules/CRM/Livewire/Seeding/SeedingCampaignsIndex.php` (4 variants; product-brand check), `Seeding/SeedingCreatorsPanel.php` (hard filter), `Seeding/ShipmentsPanel.php` (shipment CRUD + manual link/unlink = XMC-002 confirm/deny; posted/postedAt read-only).
- `resources/views/livewire/crm/{clients,brands,products,campaigns,seeding-campaigns}-index.blade.php`, `{campaign,seeding}-creators.blade.php`, `seeding-shipments.blade.php` — NEW.
- `resources/views/crm/{clients,brands,products,campaigns,campaign-detail,seeding,seeding-detail}.blade.php` — NEW page wrappers; `crm/index.blade.php` landing now links all areas (Results card stays a Step 4 placeholder).
- `app/Modules/CRM/routes.php` — 7 new routes under the existing `can:crm.view` group; `CrmServiceProvider` — 8 Livewire registrations.

**Shared / config**
- `app/Shared/Enums/{CampaignStatus,SeedingCampaignStatus,ShipmentStatus,SeedingType}.php` — presentation `label()` added (values untouched).
- `app/Modules/CRM/Models/Campaign.php` — `@property` annotations (PHPStan; sibling-model convention).
- `config/qds.php` — `matching.enabled` flag (default off; cadence flagged non-canonical); `routes/console.php` — hourly schedule.

**Tests (68 new)**
- `tests/Feature/Enrichment/ShipmentEvidenceSourceTest.php` — binding, evidence shape, D1 exclusions, story targets, and the **end-to-end AC-M1-020 test** (shipment + recognition → SEEDED mention with shipment reference via the real AttributionService).
- `tests/Feature/Enrichment/SeededContentLinkerTest.php` — auto-link at HIGH/MEDIUM, LOW stays queued, human-blessed paths, no-guessing, stale/foreign references, idempotency, campaign ambiguity, command gating.
- `tests/Feature/Monitoring/ContentMatchFeedbackTest.php` — XMC-002 semantics incl. never-clobber.
- `tests/Feature/Crm/{Clients,Brands,Products,Campaigns,SeedingCampaigns}CrudTest.php`, `{CampaignCreators,SeedingCreators}PanelTest.php`, `ShipmentsPanelTest.php` — CRUD, closed-set validation, transition audits, AC-M3-007 blocks, XMC-002 manual confirm/deny end-to-end, view/manage split incl. direct-mutator bypass.

## 3. Canonical documents relied upon

- `docs/50-modules/module-3-crm-seeding.md` — REQ-M3-005..008, AC-M3-007/009/010/012/013, §5 XMC-002; §2.5 four variants.
- `docs/50-modules/module-1-monitoring.md` — AC-M1-003 (proving record), AC-M1-020 (seeded-content detection ↔ REQ-M3-008).
- `docs/30-data-model/00-data-model.md` — ENT-Client/Brand/Product/Campaign/SeedingCampaign/Shipment shapes; Mention.campaignId "Set by content-to-campaign matching"; Shipment.postedAt = publish time of resulting content.
- `docs/00-meta/03-glossary.md` — ENUM-CampaignStatus/SeedingCampaignStatus/ShipmentStatus/SectorLabel/ConfidenceLevel/VerificationStatus; MetricTier CONFIRMED = "authorized analytics or manual agency input".
- `docs/70-shared/00-ownership-matrix.md` — M3 owns the six entities; Mention is M1-owned (→ XMC-002); **RecognitionDetection has no M3 reader** (→ matching lives in the shared platform layer, as the req-matrix's REQ-M3-008 row also states: SVC-EnrichmentAI).
- `docs/20-cross-cutting/00-data-principles.md` — DP-001 (tiered values in UI), DP-003/DP-004 (confidence + review loop).
- `docs/05-decisions/decision-log.md` — ADR-0012 (hand-built Livewire), ADR-0001 (courier APIs stay out), ADR-0014/0015 (Step 2 context).
- `docs/80-delivery/00-roadmap.md` — P3 exit criterion "matching runs with low-confidence items queued for review".

## 4. Known deviations / open conflicts

- **Step-3 spec decisions D1–D6** (spec §3) are implementer choices on canon-silent points — evidence only for dispatched shipments; auto-link cut-point mirrors `ConfidenceAssessment::needsHumanReview()` (no numeric threshold exists in canon — same convention the P1 review queue uses); XMC-002 recorder as sole `mentions.campaign_id` writer with ambiguity-goes-to-humans; case-insensitive brand-NAME restriction matching; recipient/product UI coherence limits; postedAt = earliest publish. Reviewer to confirm each.
- **No dedicated match-review screen.** Low-confidence outcomes are mentions in the EXISTING shared review queue (the mention IS the match record; canon defines no candidate/queue entity). Verify this reading of AC-M3-013 + the roadmap exit criterion.
- **Matching cadence + `qds.matching.enabled` default (off)** are operational config, not canon — same flagged class as the other cadences.
- **`ShipmentEvidence` (P1 code) got additive helpers** (reference grammar). No behavior change; flag if considered a lock violation on IMPLEMENTED items.
- **Audit superset** continues (created/updated/deleted on master data + attach/detach + match confirm/deny).
- **FACT-Shipment/FACT-SeedingContent loaders intentionally NOT built** (results plumbing → Step 4); `NeonAnalyticsService` still declares them as pending P3 work — Step 4 must pick this up.
- **Deep review not run** — see the TODO banner at the top of this file.

## 5. Tests & checks

- **Executed (passing):**
  - `XDEBUG_MODE=off php artisan test` — **519 passed (2071 assertions)**; 68 new since Step 2's 451; full-suite regression clean.
  - `XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=1G` — level 6, **no errors**.
  - `XDEBUG_MODE=off vendor/bin/pint --test` — **passed**.
- **Executed (failing / skipped):** none.
- **Not executed:** the in-session adversarial review workflow (skipped on product-owner instruction — see TODO banner); manual browser walkthrough (feature tests render full pages via HTTP + Livewire harness); docs linter (no `docs/` file touched this step).
- **External integrations not verified:** none — matching is internal-only; courier APIs are out of scope.

---

## 6. Review checklist

> Reviewer: mark `[x]` only after you have **verified** the item. Add a note per item.
> Categories mirror policy §2 (Review Model responsibilities).

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
- [ ] Migrations are reversible or the destructive risk is called out and accepted. *(No migrations this step — verify none slipped in.)*
- [ ] Schema matches the data model; constraints / indexes / FKs are correct. *(Unchanged from Step 1.)*

### 6.6 Test adequacy
- [ ] Tests cover the new behavior, not just the happy path.
- [ ] Failure modes and boundaries are asserted.

### 6.7 Adversarial verification
- [ ] Each finding below was independently verified (not taken on first read).

---

## 7. Suggested review scenarios — where to focus

1. **Matching correctness & ownership seams** — *Focus:* `SeededContentLinker`, `ShipmentContentWriter`, `ContentMatchFeedbackRecorder`, `ShipmentEvidenceSource`. *Do:* trace one content item from ingestion fixtures through attribution (SEEDED, signal `shipment-record:N`) to pivot + posted + campaign_id; verify the platform layer never queries M3 tables directly (only via the two contracts) and never writes mentions directly (only via XMC-002). *Risk if wrong:* ownership-matrix violation at the heart of REQ-M3-008.
2. **AC-M3-013 both halves** — *Focus:* `SeededContentLinkerTest`, `ReviewQueue` (P1). *Do:* confirm LOW/UNKNOWN SEEDED-adjacent outcomes actually appear in the existing review-queue UI and are NEVER auto-linked; confirm a human decision there flows into the next linker run. *Risk if wrong:* low-confidence matches silently commit (DP-004 violation).
3. **Authorization matrix over 8 new surfaces** — *Focus:* every new component + `routes.php`. *Do:* CLIENT_VIEWER on all 7 routes and every mount; crm.view-only user against every persisting mutator with client-set state (the tests model this — try to find a missed method, e.g. panel state combinations). *Risk if wrong:* privilege escalation into campaign/shipment writes.
4. **AC-M3-007 hard-filter completeness** — *Focus:* `BrandRestrictionGuard` + both creators panels + `ShipmentsPanel`. *Do:* check every path that puts a creator into a campaign/seeding context passes the guard (shipments rely on the attached-creators restriction — is that airtight? can a creator be attached, restricted afterwards, then shipped?). *Risk if wrong:* the canon "hard filter" is bypassable.
5. **Status-transition audits (AC-M3-009/012)** — *Focus:* campaigns/seeding/shipments save() paths. *Do:* verify from→to context correctness incl. unchanged-status no-op; consider concurrent edits. *Risk if wrong:* transitions unrecorded.
6. **Posted-state lifecycle (D6)** — *Focus:* `ShipmentContentWriter::refreshPostedState()`. *Do:* link/unlink permutations incl. null published_at content and multi-content shipments; verify postedAt is the earliest and nulls out cleanly. *Risk if wrong:* FACT-Shipment (Step 4) inherits wrong post-rates.
7. **Scale/perf of the linker** — *Focus:* `SeededContentLinker::run()` full-scan of SEEDED mentions each pass. *Do:* judge acceptability at roster scale; a watermark like the analytics loaders is the obvious hardening. *Risk if wrong:* hourly job degrades as mentions accumulate.

## 8. High-risk areas

- `app/Platform/Enrichment/Matching/SeededContentLinker.php` — the REQ-M3-008 heart: cut-point logic, signal parsing, ambiguity handling.
- `app/Modules/Monitoring/Services/ContentMatchFeedbackRecorder.php` — writes an M1-owned column on M3's behalf; wrong semantics corrupt attribution.
- `app/Modules/CRM/Livewire/Seeding/ShipmentsPanel.php` — the largest new surface: CRUD + link/unlink + authz all in one component.
- `app/Platform/PlatformServiceProvider.php` — the Null→real evidence rebind switches ON automatic SEEDED classification globally; behavior of every future enrichment run changes.

---

## 9. Reviewer findings

> Filled by the REVIEW model. One checkbox per finding; check when resolved/dispositioned.

- [ ] —

## 10. Review sign-off

- **Reviewer:** —
- **review_status → REVIEWED on:** —
- **outcome:** —
- **Summary:** —
