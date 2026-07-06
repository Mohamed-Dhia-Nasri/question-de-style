<!--
  BUILD SPEC (pre-implementation) — written for an IMPLEMENTATION model.
  NOT a review handoff and NOT canonical docs. Lives in reviews/ (outside the
  linted docs/ tree) as a working artifact. On completion the implementer
  writes a separate reviews/REVIEW-module3-data-foundation-<date>.md handoff.
-->

# Build Spec — Module 3 (CRM & Seeding), Step 1 of 4: Data Foundation

- **phase:** P3 · **service:** `SVC-CRM`
- **spec:** `docs/50-modules/module-3-crm-seeding.md`
- **status:** READY TO BUILD (decisions D1, D2 confirmed by product owner 2026-07-05)
- **prereq read (Rule 1):** `docs/00-meta/00-index.md` reading order → AGENTS.md rules 1–11 → ownership matrix → `docs/30-data-model/00-data-model.md` §2 envelopes + M3 entity shapes → glossary enums.

This step delivers **only the persistence + domain layer** for all Module-3-owned entities — migrations, Eloquent models, factories, policies, and the `SVC-CRM` write-path seam. **No UI, no merge logic, no matching, no results** (those are Steps 2–4). This is the schema everything else binds to; get it exactly right so later steps never re-touch migrations.

Mirror the Module 1 foundation conventions exactly (proven, green): Postgres `CHECK` constraints mirroring every closed enum, embedded envelopes as `jsonb` via `App\Shared\Casts\AsValueObject` / `AsValueObjectCollection`, per-entity factories, per-entity policies + a permission, `XDEBUG_MODE=off` for tests, PHPStan level 6, Pint clean.

---

## 0. Ground truth — read, never restate (Rule 3)

- **Field shapes** are canonical in `docs/30-data-model/00-data-model.md`. Build exactly those fields — no more (Rule 4: STOP, never invent a field).
- **Write-ownership**: M3 write-owns all 14 entities below (`docs/70-shared/00-ownership-matrix.md`). Never write M1/M2 entities.
- **Enums** already exist as PHP classes in `app/Shared/Enums/` — **reuse, do not recreate**: `CampaignStatus`, `SeedingCampaignStatus`, `ShipmentStatus`, `TaskStatus`, `RelationshipStatus`, `RoleName`, `SectorLabel`, `MetricTier`.
- **Value objects** already exist in `app/Shared/ValueObjects/` — reuse: `MetricValue`, `Provenance`, `ConfidenceAssessment`, `ReachEstimate`, casts in `app/Shared/Casts/`.

---

## 1. Current state (starting point — do not rebuild)

The 5 M3 tables below were built by M1 as read-anchors and **already carry the full canonical shape + doctrine** (enum CHECKs, `platform_accounts.provenance`, `creators` NOT-NULL timestamps). Verify each against its data-model shape; extend only where noted. Models exist in `app/Modules/CRM/Models/`, factories in `database/factories/`.

| Table | Shape complete? | Step-1 action |
|---|---|---|
| `clients` (ENT-Client) | ✅ id/name/country | Verify only. Add policy. |
| `brands` (ENT-Brand) | ✅ client_id/name/sector/aliases | Verify only. Add policy. |
| `creators` (ENT-Creator) | ✅ display_name/primary_language/relationship_status + NOT-NULL timestamps | Verify only. Add policy. |
| `platform_accounts` (ENT-PlatformAccount) | ✅ creator_id/platform/handle/bio/external_links/follower_count/provenance | Verify only. Add policy. |
| `campaigns` (ENT-Campaign) | ⚠️ missing `creatorIds` | Add `campaign_creator` pivot (§2). Add policy. |

`User` (`app/Models/User.php`) + `UserFactory` + `UserPolicy` exist; roles/permissions run on **spatie/laravel-permission** (P0). `SVC-CRM` boundary exists as `App\Modules\CRM\CrmServiceProvider` + `App\Modules\CRM\Services\IngestedProfileSync` (the `PlatformAccount` profile-sync write seam).

---

## 2. Build — new M3 entities

Create migration + model + factory + policy for each. Field shapes are canonical in the data model (linked); do not restate or add fields. All are **manual/internal** entities → **no `Provenance` envelope** (only `PlatformAccount` embeds Provenance, already done).

1. **`products`** — ENT-Product (`#ent-product`). FK `brand_id`. `unit_value` = `MetricValue` jsonb via `AsValueObject`, nullable, **tier `CONFIRMED`** (agency-known price). `category` → CHECK against `ENUM-SectorLabel`.
2. **`contacts`** — ENT-Contact (`#ent-contact`). FK `creator_id` (required). email/phone/postal_address/preferred_channel, all nullable, **manual entry only**. **GDPR (DP-005):** row must be hard-deletable — no append-only trigger, no soft-delete blocker. Contact auto-extraction is **DEF-002** → not built; any such affordance renders "unavailable" (Rule 8 — a Step-2 UI concern).
3. **`brand_preferences`** — ENT-BrandPreference (`#ent-brandpreference`). FK `creator_id`. `preferred_brands` / `restricted_brands` = jsonb **string** lists (shape is "list of string", not FK ids). notes nullable.
4. **`seeding_campaigns`** — ENT-SeedingCampaign (`#ent-seedingcampaign`). FKs: `campaign_id` (nullable), `brand_id` (required), `product_id` (nullable). `status` → CHECK `ENUM-SeedingCampaignStatus`. `seeding_type` → **4-variant closed set, confirmed tokens** `GIFTING / GIFTING_WITH_POST / PAID_PLUS_PRODUCT / ORGANIC` with a CHECK constraint (see D1). Add `seeding_campaign_creator` pivot for `creatorIds`.
5. **`shipments`** — ENT-Shipment (`#ent-shipment`). FKs: `seeding_campaign_id` (required), `creator_id` (required), `product_id` (required — the cross-creator aggregation key). `status` → CHECK `ENUM-ShipmentStatus`. `product_value_at_ship` = `MetricValue` jsonb, nullable, tier `CONFIRMED`. Lifecycle columns: tracking_number, shipped_at, delivered_at, quantity, posting_required, posted, posted_at (per shape). `resultingContentIds` → `shipment_resulting_content` pivot (shipment_id, content_item_id), **empty until REQ-M3-008 matching in Step 3** (see D2).
6. **`communication_logs`** — ENT-CommunicationLog (`#ent-communicationlog`). FKs `creator_id` (required), `campaign_id` (nullable). channel/direction/summary/occurred_at required per shape.
7. **`document_attachments`** — ENT-DocumentAttachment (`#ent-documentattachment`). FKs `creator_id` / `campaign_id` both nullable (either, both, or neither — no XOR required by shape). file_name/storage_url/uploaded_at required.
8. **`tasks`** — ENT-Task (`#ent-task`). `status` → CHECK `ENUM-TaskStatus`. FKs `assignee_user_id` / `creator_id` / `campaign_id` all nullable. title required, due_at nullable.

**Pivots to add:** `campaign_creator` (campaign_id, creator_id), `seeding_campaign_creator` (seeding_campaign_id, creator_id), `shipment_resulting_content` (shipment_id, content_item_id). Composite unique keys; FK constraints both sides.

**Role/User (ENT-User `#ent-user`, ENT-Role `#ent-role`):** Do **not** create parallel `roles`/`users` tables. Reconcile to the existing spatie substrate: ENT-User.roleId ("exactly one role") = the user's single spatie role; ENT-Role.name = `ENUM-RoleName`; ENT-Role.permissions = spatie permissions. If the "exactly one role" invariant is not enforceable on the current spatie setup without a schema change, **STOP and ask** (Rule 4) — do not silently add a `role_id` column duplicating spatie.

---

## 3. `SVC-CRM` write-path seam

- Establish `SVC-CRM` as the **single write path** for `Creator` and `PlatformAccount` (ownership matrix: "all Creator writes route through the CRM/ingestion service"). A thin, well-named service method suffices for Step 1; full identity-merge logic is Step 2.
- Define the **XMC-001** creator-proposal contract *interface* (M1/M2 → SVC-CRM propose a new creator/platform account). Body lands in Step 2; the seam + DTO shape land now so M1/M2 have a target. `IngestedProfileSync` already covers the profile-sync half.
- **XMC-002** (content-match feedback, M3 → M1) is Step 3 — name it, don't build it.
- Register all new policies + a `crm.manage` permission (staff) in `CrmServiceProvider` / the permission seeder, following the M1 policy-registration pattern. Keep `User`/`Role` writes ADMIN-only (AC-M3-018).

---

## 4. Doctrine to enforce (Rule 9)

- Every closed enum → a Postgres `CHECK` constraint mirroring the glossary values (as the stubs already do).
- `MetricValue` fields (`products.unit_value`, `shipments.product_value_at_ship`) → `jsonb` + `AsValueObject`, tier `CONFIRMED`. Note: `MetricValue` has **no currency field** (canonical shape is amount+tier only) — if a value needs currency, STOP and ask; do not invent a column.
- No `Provenance` on manual M3 entities.
- No append-only triggers on M3 operational entities (they are mutable CRM records — unlike `metric_snapshots`).
- Contacts must remain hard-deletable for DP-005.

---

## 5. Decisions

- **D1 — `seedingType` tokens — CONFIRMED.** Encode the 4 variants as `GIFTING / GIFTING_WITH_POST / PAID_PLUS_PRODUCT / ORGANIC` with a CHECK constraint. The variants are canonical **prose** in module-3 §2.5 but not yet a glossary `ENUM-*`; **flag as a deviation needing a glossary `ENUM-SeedingType` amendment** (same class as M1's flagged deviations).
- **D2 — `resultingContentIds` as pivot — CONFIRMED.** Model as `shipment_resulting_content` pivot for query-ability by `FACT-SeedingContent`; flag as a modeling deviation vs. the "list-on-entity" data-model shape.
- **D3 — CLIENT_VIEWER report entity — NOT a Step-1 blocker.** REQ-M3-012 positive delivery needs a canonical report entity + user↔client link that don't exist; resolved by a **separate ADR before Step 4** (agreed). Step 1 builds no report entity.

---

## 6. Acceptance criteria for Step 1

- Every M3-owned entity in the ownership matrix is migrated and persistable with its exact canonical shape.
- Every closed-enum column rejects out-of-set values (CHECK) — proven by a failing-insert test per enum (incl. the 4 `seeding_type` tokens).
- `MetricValue` envelopes round-trip through the cast; tier is `CONFIRMED`.
- All pivots enforce composite uniqueness + referential integrity.
- A `Contact` row is hard-deletable (DP-005 smoke test).
- `Creator`/`PlatformAccount` writes go through `SVC-CRM`; direct writes from a non-owner path are not part of the sanctioned API.
- Factories exist for every new entity; every migration invariant has a test.
- **Green bar:** full suite (new tests added to the existing ~350), PHPStan level 6, Pint — all with `XDEBUG_MODE=off`.

## 7. Out of scope (later steps)

Identity **merge** + merge/un-merge audit table + merge review queue (Step 2); campaign/seeding **UI** + content-to-campaign **matching** + review queue + XMC-002 (Step 3); **results** (EMV/CPE/CPM), product-level aggregation wiring, documents/tasks UI, full roles/permissions incl. CLIENT_VIEWER (Step 4). No new Livewire components in Step 1.

## 8. Handoff

On completion, per the deep-review convention, write `reviews/REVIEW-module3-data-foundation-<YYYY-MM-DD>.md` from `reviews/_TEMPLATE.md` (all checkboxes `[ ]`, `review_status: PENDING_REVIEW`) and append any new flagged schema deviations (D1 `ENUM-SeedingType`, D2 pivot, plus the pre-existing handle-uniqueness) to the deviation register / project memory.
