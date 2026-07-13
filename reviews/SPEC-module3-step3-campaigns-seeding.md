<!--
  BUILD SPEC (pre-implementation) — working artifact, NOT canonical docs.
  Written and implemented in the same session (2026-07-06) by the
  implementation model; the deep review happens afterwards via
  reviews/REVIEW-module3-campaigns-seeding-<date>.md.
-->

# Build Spec — Module 3 (CRM & Seeding), Step 3 of 4: Campaigns, Seeding, Shipments & Content Matching

- **phase:** P3 · **service:** `SVC-CRM` (+ matching in `SVC-EnrichmentAI`)
- **spec:** `docs/50-modules/module-3-crm-seeding.md` §2.5–2.7 (REQ-M3-005/006/007), §2.7 (REQ-M3-008), §5 (XMC-002)
- **prereq read (Rule 1):** same reading order as Steps 1–2, plus `reviews/REVIEW-module3-data-foundation-2026-07-06.md` and `reviews/REVIEW-module3-identity-merge-2026-07-06.md` (both PENDING_REVIEW).

Step 3 delivers the operational half of Module 3: master-data CRUD (Client → Brand → Product), campaign management (ENUM-CampaignStatus, transitions recorded), seeding-campaign management (the four ENUM-SeedingType variants), shipment tracking (ENUM-ShipmentStatus, manual updates — courier APIs optional and out of the frozen stack), the brand-preference hard filter (AC-M3-007), and **automatic content-to-campaign matching** (REQ-M3-008, AC-M3-013, AC-M1-020) with XMC-002 feedback. Results/EMV/CPE/CPM, FACT-Shipment/FACT-SeedingContent loaders, documents/tasks, and CLIENT_VIEWER are **Step 4** — do not pull forward.

---

## 1. Current state (do not rebuild)

- Step 1 shipped ALL schema/models/factories/policies for Client, Brand, Product, Campaign (+`campaign_creator`), SeedingCampaign (+`seeding_campaign_creator`), Shipment (+`shipment_resulting_content`), with enum CHECKs and `crm.view`/`crm.manage` policies. **No UI, no services for them.**
- Step 2 shipped the creators UI + CreatorWriter + XMC-001. `UsersIndex`/`CreatorsIndex` are the CRUD reference pattern (ADR-0012).
- **M1/P1 already built the matching engine.** `App\Platform\Enrichment\Attribution\MentionClassifier` (reviewed P1 code) aligns shipment evidence with independently-evidenced brand relevance (recognition `detected_brand`, scoped hashtags) inside the configured `qds.enrichment.attribution.shipment_window_days` window, and emits SEEDED mentions whose `classification.signals` include each aligned shipment's `reference` (AC-M1-003 proving record; AC-M1-020). SEEDED is produced only at HIGH (strong relevance + verified timing) or MEDIUM confidence. Weak/ambiguous evidence yields UNKNOWN@LOW → the existing shared review queue (`ReviewQueue`, predicate `AI_ASSESSED` + `LOW/UNKNOWN` = `ConfidenceAssessment::needsHumanReview()`), with corrections via `ReviewService` + append-only `review_actions`.
- `App\Platform\Enrichment\Contracts\SeedingEvidenceSource` is bound to `NullSeedingEvidenceSource` ("Module 3 (P3) provides the real implementation"). `ShipmentEvidence` DTO already defines the fields (reference, brandId, brandName, productLabel, shippedAt, deliveredAt, campaignId).
- `mentions.campaign_id` exists (nullable FK → campaigns), documented as "Set by content-to-campaign matching (REQ-M3-008)"; **nothing writes it yet**. Mention is M1-owned; Campaign/SeedingCampaign/Shipment are M3-owned.
- FACT-Shipment/FACT-SeedingContent tables + seeding rollups exist and are empty; their loaders are declared P3 work in `NeonAnalyticsService` — **assigned to Step 4** here (results plumbing).

## 2. Build

### 2.1 Master data + campaigns + seeding + shipments UI (REQ-M3-005/006/007)

Hand-built Livewire on the `UsersIndex` pattern (searchable/sortable/paginated, modal create/edit, server-side authorization in mount() AND every mutating action, audit on sensitive changes):

- **`crm.clients-index`** (`/crm/clients`) — ENT-Client CRUD (name, country). Deletes blocked by restrict FKs surface friendly errors.
- **`crm.brands-index`** (`/crm/brands`) — ENT-Brand CRUD (client select, name, ENUM-SectorLabel select, aliases one-per-line).
- **`crm.products-index`** (`/crm/products`) — ENT-Product CRUD (brand select, name, sku, variant, unitValue as MetricValue tier CONFIRMED — manual agency input per the glossary tier definition, category).
- **`crm.campaigns-index`** (`/crm/campaigns`) — ENT-Campaign CRUD (name, brand, ENUM-CampaignStatus, startAt/endAt). **AC-M3-009:** status is Rule::in-validated against the closed set and every status change is audit-logged with from→to (`campaign.status_changed`).
- **`/crm/campaigns/{campaign}`** — detail page: **`crm.campaign-creators`** panel (attach/detach participating creators — the `campaign_creator` pivot). **AC-M3-007:** attaching a creator whose ENT-BrandPreference `restrictedBrands` contains the campaign's brand name is **blocked** with a caught validation error (hard filter per module-3 §2.3). Page also lists the campaign's seeding runs.
- **`crm.seeding-campaigns-index`** (`/crm/seeding`) — ENT-SeedingCampaign CRUD: name, **exactly one of the four SeedingType variants (AC-M3-010)**, brand, optional product (must belong to the brand), optional parent campaign, ENUM-SeedingCampaignStatus (status changes audited like campaigns).
- **`/crm/seeding/{seedingCampaign}`** — detail page: **`crm.seeding-creators`** panel (same hard filter vs the seeding brand) + **`crm.seeding-shipments`** panel:
  - shipment create/edit: recipient **from the seeding run's attached creators**, product (brand's products; defaults to the run's product), quantity, productValueAtShip (MetricValue tier CONFIRMED), postingRequired, trackingNumber, shippedAt/deliveredAt, **ENUM-ShipmentStatus select (manual updates always supported — AC-M3-012)**; status changes audited.
  - `posted`/`postedAt` are **matching-owned, read-only** in the panel (data model: postedAt = publish time of the resulting content).
  - per-shipment **resulting content**: list + **"Link content"** (manual confirm) and **"Remove link"** (deny) — the operator-facing half of **XMC-002** (§2.3).

### 2.2 Matching (REQ-M3-008) — reuse the P1 engine; the Mention IS the match record

No new inference, no new threshold, no new queue surface is invented:

1. **`App\Modules\CRM\Services\ShipmentEvidenceSource`** implements the existing `SeedingEvidenceSource` contract (rebound in `PlatformServiceProvider`, same precedent as `IngestedProfileSync`): for a ContentItem/Story target → recipient creator's shipments **with `shippedAt` set** (an unshipped product cannot evidence seeding — flagged app-layer choice) → `ShipmentEvidence(reference: "shipment-record:{id}", brand/product labels from the seeding run, campaignId: the run's parent campaign)`. This switches ON the existing AC-M1-020 path: attribution now classifies SEEDED with shipment references in the signals.
2. **`App\Platform\Enrichment\Matching\SeededContentLinker`** (SVC-EnrichmentAI — the layer the req-matrix names for REQ-M3-008; M3 is not a listed reader of ENT-RecognitionDetection, so matching cannot live in the CRM module): scans content-item Mentions typed SEEDED that are **auto-linkable** (`AI_ASSESSED` @ HIGH/MEDIUM — the established cut-point convention, mirrored: `needsHumanReview()` rows are NOT auto-linked, satisfying AC-M3-013) **or human-blessed** (HUMAN_REVIEWED/HUMAN_CORRECTED/CONFIRMED). For each shipment reference in the signals (recipient re-verified): writes the M3 side through the **`ShipmentContentLinker`** platform contract (impl `App\Modules\CRM\Services\ShipmentContentWriter`: idempotent `shipment_resulting_content` attach + `posted=true` + `postedAt` = earliest resulting publish time), and the M1 side through **XMC-002** when the linked shipments resolve to exactly one parent campaign. Human-corrected SEEDED mentions without shipment references are counted+reported, not guessed — the operator links manually (§2.1).
3. **XMC-002 — `App\Modules\Monitoring\Contracts\ContentMatchFeedback`** (contract in the write-owner module, same as XMC-001), implemented by `App\Modules\Monitoring\Services\ContentMatchFeedbackRecorder`, bound in `MonitoringServiceProvider`: `confirm(content, campaignId)` sets `mentions.campaign_id` where unset; `deny(content, campaignId)` clears it where it matches. Both audit-logged. Callers: the linker (auto path) and the shipments panel (manual confirm/deny).
4. **`qds:link-seeded-content`** console command (self-gating on `qds.matching.enabled`, scheduled hourly after the enrichment sweep). Cadence is NOT canonically decided — same flagged class as the other cadences in `config/qds.php`.
5. **Review queue:** low-confidence outcomes already land in the existing shared review queue as mentions (with the shipment/recognition signals visible); a human decision there feeds the next linker run. **No new queue table, no new queue screen** — consistent with canon defining no candidate/queue entity and with the merge-feature precedent (ADR-0014: "no candidate queue").

### 2.3 Doctrine to enforce (Rule 9)

- Matching confidence rides the Mention's existing `ConfidenceAssessment` (DP-003); nothing auto-commits below the established cut-point (DP-004); corrections flow through the existing `ReviewService`/`review_actions` (AC-M1-002).
- All M3 writes stay in the CRM module or its write seams; the platform linker writes M3 rows only via the `ShipmentContentLinker` contract and M1 rows only via XMC-002. `Mention.campaign_id` is written **only** by the M1-side recorder.
- MetricValue envelopes (unitValue, productValueAtShip) carry tier CONFIRMED (manual agency input — glossary ENUM-MetricTier).
- CLIENT_VIEWER reaches none of the new surfaces; every mutating Livewire action re-authorizes server-side.

## 3. Decisions (implementer-flagged, reviewer to confirm)

- **D1 — evidence only for dispatched shipments** (`shipped_at` NOT NULL). Unshipped/pending shipments produce no ShipmentEvidence.
- **D2 — auto-link cut-point = the codebase's established review convention** (auto at AI_ASSESSED HIGH/MEDIUM; review at LOW/UNKNOWN). No numeric threshold exists in canon; this mirrors `ConfidenceAssessment::needsHumanReview()` rather than inventing one.
- **D3 — `mentions.campaign_id` written by M1-side XMC-002 recorder only**, on confirm/deny; set only when the aligned shipments share exactly one non-null parent campaign (ambiguity → left null for humans).
- **D4 — brand-preference hard filter matches restricted brand NAMES case-insensitively** (canonical shape is plain string lists); sector-level restrictions are not interpreted in v1.
- **D5 — shipment recipient limited to the seeding run's attached creators; product select limited to the run's brand** (UI-level coherence choices, not DB constraints).
- **D6 — postedAt = earliest linked content publish time**; recomputed on unlink.
- **No schema changes.** No new tables, no migrations. FACT loaders deferred to Step 4.

## 4. Acceptance criteria for Step 3

- AC-M3-009 (campaign status enum + transitions recorded), AC-M3-010 (exactly one seeding variant + status enum), AC-M3-012 (shipment status enum, manual updates), AC-M3-007 (restriction hard-block on campaign/seeding join).
- AC-M3-013: high-confidence matches auto-link; low-confidence route to the (existing) review queue with a ConfidenceAssessment and are not auto-linked. AC-M1-020 activates end-to-end (shipment + recognition → SEEDED mention → resulting-content link).
- XMC-002 exists, is bound, and is exercised by both the auto path and the operator confirm/deny.
- Tests for every new service and screen; green bar (full suite, PHPStan 6, Pint) with XDEBUG_MODE=off.

## 5. Out of scope

- Results (EMV/CPE/CPM), FACT-Shipment/FACT-SeedingContent loaders, product aggregation UI, documents/tasks, CLIENT_VIEWER + report approval (Step 4; CLIENT_VIEWER still needs its ADR first).
- Courier API integrations (optional, outside the frozen stack — manual status updates only).
- Any merge/reassignment feature (ADR-0014) and any change to canonical docs.

## 6. Handoff

On completion write `reviews/REVIEW-module3-campaigns-seeding-<YYYY-MM-DD>.md` from `reviews/_TEMPLATE.md` (PENDING_REVIEW). **Per the product owner's instruction (2026-07-06), the in-session adversarial review workflow is intentionally SKIPPED for Step 3** — the handoff must carry an explicit TODO making the independent deep review mandatory before the step is considered accepted.
