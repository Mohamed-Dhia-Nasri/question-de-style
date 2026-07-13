<!--
  Deep-Review Handoff — Module 3 (CRM & Seeding), Step 1: Data Foundation
  Written by the IMPLEMENTATION model. Consumed by a SEPARATE review model.
  Build spec: reviews/SPEC-module3-step1-data-foundation.md
-->

# Deep Review — Module 3 Step 1: CRM & Seeding Data Foundation

- **review_status:** REVIEWED (2026-07-07, adversarial multi-agent pass — see `reviews/REVIEW-module3-FINDINGS-2026-07-07.md`)
- **outcome:** ACCEPT WITH FIXES. Step-1 findings: L1 (one-account-per-platform DB backstop). No blockers originate in this step.
- **implemented_by:** claude-fable-5
- **implementation_date:** 2026-07-06
- **reviewer:** unassigned
- **deep_review_trigger:** database migrations (11 new tables), authorization changes (new permission + 13 policies), canonical architecture (SVC-CRM write seam, XMC-001 contract) — major feature step

---

## 1. Implementation summary

Built the complete persistence + domain layer for all Module-3-owned entities per `reviews/SPEC-module3-step1-data-foundation.md`: 8 new tables (`products`, `contacts`, `brand_preferences`, `seeding_campaigns`, `shipments`, `communication_logs`, `document_attachments`, `tasks`), 3 pivots (`campaign_creator`, `seeding_campaign_creator`, `shipment_resulting_content`), Eloquent models + factories for each, `crm.manage` permission + 13 policies covering every M3 CRM record, and the SVC-CRM write seam (`CreatorWriter` + XMC-001 `CreatorProposals` interface/DTO bound to a `NotYetImplemented` placeholder). **IN:** schema, models, factories, policies, seams, tests. **OUT (later steps):** identity merge (2), UI / content matching / XMC-002 (3), results + CLIENT_VIEWER reporting (4). No Livewire components; no User/Role schema changes (single-role invariant already enforced at the application layer on the spatie substrate — no parallel tables created).

## 2. Changed files

**Enum (new)**
- `app/Shared/Enums/SeedingType.php` — 4 confirmed variant tokens (spec D1); flagged for glossary `ENUM-SeedingType` amendment.

**Migrations (new, `2026_07_05_2300xx`)**
- `...230001_create_products_table.php` — ENT-Product; `unit_value` jsonb envelope; `category` CHECK vs ENUM-SectorLabel (20 values).
- `...230002_create_contacts_table.php` — ENT-Contact; all detail fields nullable; hard-deletable (DP-005), no soft-delete/trigger.
- `...230003_create_brand_preferences_table.php` — ENT-BrandPreference; jsonb string lists.
- `...230004_create_campaign_creator_table.php` — ENT-Campaign.creatorIds pivot; composite unique; cascade both sides.
- `...230005_create_seeding_campaigns_table.php` — ENT-SeedingCampaign + `seeding_campaign_creator` pivot; CHECKs on `seeding_type` (D1 tokens) and `status` (ENUM-SeedingCampaignStatus).
- `...230006_create_shipments_table.php` — ENT-Shipment + `shipment_resulting_content` pivot (D2); `status` CHECK (ENUM-ShipmentStatus); `product_id` NOT NULL (aggregation key); `product_value_at_ship` jsonb envelope.
- `...230007_create_communication_logs_table.php` — ENT-CommunicationLog; channel/direction plain strings (no glossary enum → no CHECK).
- `...230008_create_document_attachments_table.php` — ENT-DocumentAttachment; creator/campaign FKs both nullable, no XOR (per shape).
- `...230009_create_tasks_table.php` — ENT-Task; `status` CHECK (ENUM-TaskStatus); `assignee_user_id` → users, nullOnDelete (follows `content_hashtags.resolved_by` precedent).

**Models (new, `app/Modules/CRM/Models/`)**
- `Product.php`, `Contact.php`, `BrandPreference.php`, `SeedingCampaign.php`, `Shipment.php`, `CommunicationLog.php`, `DocumentAttachment.php`, `Task.php` — canonical fields only; enum + `AsValueObject` casts; typed relations.

**Models (modified)**
- `Campaign.php` — added `creators()` (campaign_creator), `seedingCampaigns()`, `communicationLogs()`, `documentAttachments()`, `tasks()`; docblock no longer says the pivot is missing.
- `Creator.php` — added `contacts()`, `brandPreferences()`, `campaigns()`, `seedingCampaigns()`, `shipments()`, `communicationLogs()`, `documentAttachments()`, `tasks()`.
- `Brand.php` — added `products()`, `seedingCampaigns()`.

**Factories (new, `database/factories/`)**
- `ProductFactory`, `ContactFactory`, `BrandPreferenceFactory`, `SeedingCampaignFactory` (+`ofType`/`forCampaign`/`withProduct` states), `ShipmentFactory` (+`delivered` state), `CommunicationLogFactory`, `DocumentAttachmentFactory`, `TaskFactory` — synthetic data only; envelopes at tier CONFIRMED.

**Authorization**
- `app/Shared/Authorization/PermissionsCatalog.php` — added `CRM_MANAGE` (`crm.manage`), granted to staff roles (never CLIENT_VIEWER); documented why it does not cover User/Role writes (AC-M3-018).
- `app/Modules/CRM/Policies/` — 13 new policies (`Client`, `Brand`, `Creator`, `PlatformAccount`, `Campaign`, `Product`, `Contact`, `BrandPreference`, `SeedingCampaign`, `Shipment`, `CommunicationLog`, `DocumentAttachment`, `Task`): view via `crm.view`, write via `crm.manage`.

**SVC-CRM seam**
- `app/Modules/CRM/Contracts/CreatorProposals.php` — XMC-001 interface (M1/M2 → M3 proposal target).
- `app/Modules/CRM/DTO/CreatorProposal.php` — proposal payload (ENT-Creator.displayName + ENT-PlatformAccount fields + mandatory Provenance).
- `app/Modules/CRM/Services/PendingCreatorProposals.php` — Step-2 placeholder throwing `NotYetImplemented` (Pending* pattern).
- `app/Modules/CRM/Services/CreatorWriter.php` — sanctioned in-module write path for Creator/PlatformAccount.
- `app/Modules/CRM/CrmServiceProvider.php` — binds XMC-001 seam; registers all 13 policies.

**Tests (new, `tests/Feature/Crm/`)**
- `CrmSchemaTest` (tables/columns/NOT-NULLs, no soft-delete on contacts), `CrmCheckConstraintsTest` (every closed-enum CHECK incl. 4 seeding_type tokens), `CrmEnvelopeIntegrityTest` (MetricValue round-trip, tier CONFIRMED), `CrmPivotIntegrityTest` (composite unique, FK integrity, cascade), `ContactGdprDeletionTest` (DP-005), `CrmAuthorizationTest` (staff vs CLIENT_VIEWER), `CreatorWriteSeamTest` (CreatorWriter + XMC-001 binding), `CrmModelRelationshipsTest` (factories + relations). 39 tests, 253 assertions.

## 3. Canonical documents relied upon

- `docs/30-data-model/00-data-model.md` (`DATA-MODEL`) — exact field shapes for ENT-Product, ENT-Contact, ENT-BrandPreference, ENT-Campaign.creatorIds, ENT-SeedingCampaign, ENT-Shipment, ENT-CommunicationLog, ENT-DocumentAttachment, ENT-Task, ENT-User/ENT-Role; MetricValue envelope shape (amount + tier, NO currency).
- `docs/00-meta/03-glossary.md` (`GLOSSARY`) — closed sets for ENUM-SectorLabel, ENUM-SeedingCampaignStatus, ENUM-ShipmentStatus, ENUM-TaskStatus, ENUM-RoleName mirrored in the CHECK constraints.
- `docs/70-shared/00-ownership-matrix.md` (`OWNERSHIP-MATRIX`) — M3 write-owns all 14 entities; Creator/PlatformAccount writes route through SVC-CRM; XMC proposal rule.
- `docs/50-modules/module-3-crm-seeding.md` (`module-3-crm-seeding`) — §2.5 four seeding variants (D1 basis), §5 XMC-001/XMC-002 contract table, AC-M3-002/-010/-018.
- `docs/20-cross-cutting/00-data-principles.md` — DP-001 (tiering), DP-002 (provenance on external records only), DP-005 (GDPR hard-delete).
- `docs/20-cross-cutting/01-deferred-register.md` — DEF-002 (no contact auto-extraction; nothing built).
- `docs/AGENTS.md` (`AGENTS`) — rules 1–11 gating everything above.
- `reviews/SPEC-module3-step1-data-foundation.md` — the build spec; decisions D1/D2/D3.

## 4. Known deviations / open conflicts

- **D1 — `seeding_type` tokens (flagged, needs glossary amendment).** The four variants are canonical prose (module-3 §2.5) but not a glossary `ENUM-*`. Encoded as `GIFTING / GIFTING_WITH_POST / PAID_PLUS_PRODUCT / ORGANIC` (product-owner confirmed) with CHECK + `App\Shared\Enums\SeedingType`. Needs a glossary `ENUM-SeedingType` amendment.
- **D2 — `resultingContentIds` as pivot (flagged modeling deviation).** Canonical shape is "list of `ENT-ContentItem` ids" on ENT-Shipment; modeled as `shipment_resulting_content` pivot for FK integrity and FACT-SeedingContent query-ability. Same class of deviation applies to `ENT-Campaign.creatorIds` → `campaign_creator` and `ENT-SeedingCampaign.creatorIds` → `seeding_campaign_creator`.
- **User/Role reconciliation (no schema change).** ENT-User.roleId "exactly one role" maps to the single spatie role enforced at the application layer (`User::roleName()`, `syncRoles([...])` convention, UsersIndex validation). No `role_id` column and no parallel `roles` table were added. If the reviewer considers app-layer enforcement insufficient, that is an ADR conversation, not a Step-1 code change.
- **Pivot FK cascade.** Pivot rows cascade on delete of either side (participation/join rows, not entities). Entity FKs keep the default restrict except `tasks.assignee_user_id` (nullOnDelete, follows the `content_hashtags.resolved_by` precedent). Neither cascade choice is specified canonically — flagged for reviewer judgment.
- **Pre-existing (not new):** `platform_accounts` handle-uniqueness deviation and the optional `MetricValue.metric` label remain flagged from M1; nothing in this step depends on or extends them.

## 5. Tests & checks

- **Executed (passing):**
  - `XDEBUG_MODE=off php artisan test` — **389 passed (1586 assertions)**, includes the 39 new CRM tests; full-suite regression clean.
  - `XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=1G` — level 6, **no errors**.
  - `XDEBUG_MODE=off vendor/bin/pint --test` — **passed**.
  - Migrations executed against Postgres (qds_test, port 5433) via RefreshDatabase.
- **Executed (failing / skipped):** none.
- **Not executed:** `php artisan migrate` against the dev database (schema verified on the test database only); docs linter (no `docs/` file touched).
- **External integrations not verified:** none — this step has no external surface.

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
- [ ] Migrations are reversible or the destructive risk is called out and accepted.
- [ ] Schema matches the data model; constraints / indexes / FKs are correct.

### 6.6 Test adequacy
- [ ] Tests cover the new behavior, not just the happy path.
- [ ] Failure modes and boundaries are asserted.

### 6.7 Adversarial verification
- [ ] Each finding below was independently verified (not taken on first read).

---

## 7. Suggested review scenarios — where to focus

1. **Field-by-field shape audit** — *Focus:* each new migration vs `docs/30-data-model/00-data-model.md` entity tables. *Do:* diff every column (name, nullability, type) against the canonical table; confirm nothing extra was invented (Rule 4) and nothing required is missing. *Expected:* exact 1:1 with only the D1/D2 flagged deviations. *Risk if wrong:* later steps bind to a wrong schema and migrations get re-touched.
2. **CHECK constraints mirror the glossary** — *Focus:* `products.category`, `seeding_campaigns.seeding_type/status`, `shipments.status`, `tasks.status`. *Do:* compare each CHECK's value list to the glossary enum (20 SectorLabel values; 6 SeedingCampaignStatus; 7 ShipmentStatus; 5 TaskStatus; 4 D1 tokens). *Expected:* character-exact sets. *Risk if wrong:* out-of-set values persist or valid values are rejected.
3. **GDPR hard-delete path** — *Focus:* `contacts` migration + `ContactGdprDeletionTest`. *Do:* verify no trigger, no soft delete, no inbound FK to `contacts`; delete a row with dependents around it. *Expected:* clean hard delete (DP-005). *Risk if wrong:* GDPR erase obligation is unfulfillable.
4. **Write-seam sanctity** — *Focus:* `CreatorWriter`, `CreatorProposals`, `IngestedProfileSync`, ownership matrix. *Do:* confirm no NEW code path outside `app/Modules/CRM/` writes `creators`/`platform_accounts`; confirm the XMC-001 binding throws `NotYetImplemented`. *Expected:* SVC-CRM remains the only writer; seam is a declared boundary, not silent behaviour. *Risk if wrong:* ownership-matrix violation that Step 2 merge logic would inherit.
5. **Authorization matrix** — *Focus:* `PermissionsCatalog`, 13 policies, `CrmAuthorizationTest`. *Do:* verify `crm.manage` reaches all staff and never CLIENT_VIEWER; verify User/Role writes still require `users.manage`/`roles.manage` (ADMIN-only) and are NOT reachable via `crm.manage`. *Expected:* AC-M3-018 holds. *Risk if wrong:* privilege escalation to user administration.
6. **Pivot semantics** — *Focus:* the three pivot tables. *Do:* attach/detach via relations; attempt duplicate pairs and dangling FKs; delete a parent and observe cascades. *Expected:* composite uniqueness + integrity, cascade only on pivots. *Risk if wrong:* orphaned or duplicated join rows corrupt FACT-SeedingContent in Step 4.

## 8. High-risk areas

- `database/migrations/2026_07_05_230006_create_shipments_table.php` — densest canonical shape (13 columns + envelope + pivot); most room for a silent field mismatch.
- `app/Shared/Authorization/PermissionsCatalog.php` — a wrong grant here is a cross-module security defect; verify the staff list and CLIENT_VIEWER exclusion.
- `app/Modules/CRM/DTO/CreatorProposal.php` — the XMC-001 shape M1/M2 will build against in Step 2; a wrong field here propagates into two other modules.
- Pivot cascade choices (all three pivots) — not canonically specified; confirm cascade-on-parent-delete is acceptable for participation rows.

---

## 9. Reviewer findings

> Filled by the REVIEW model. One checkbox per finding; check when resolved/dispositioned.

- [ ] —

## 10. Review sign-off

- **Reviewer:** —
- **review_status → REVIEWED on:** —
- **outcome:** —
- **Summary:** —
