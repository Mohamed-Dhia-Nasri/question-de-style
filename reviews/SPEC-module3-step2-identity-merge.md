<!--
  BUILD SPEC (pre-implementation) — written for an IMPLEMENTATION model.
  NOT a review handoff and NOT canonical docs. Lives in reviews/ (outside the
  linted docs/ tree) as a working artifact. On completion the implementer
  writes a separate reviews/REVIEW-module3-identity-merge-<date>.md handoff.
-->

# Build Spec — Module 3 (CRM & Seeding), Step 2 of 4: Operator-Managed Identity & Relationship Management

- **phase:** P3 · **service:** `SVC-CRM`
- **spec:** `docs/50-modules/module-3-crm-seeding.md`
- **status:** DRAFT — product-owner scope call (2026-07-06): **creator identity is 100% a human responsibility in v1.** No automatic merge (D6) **and no dedicated merge/un-merge/reassignment feature at all** (D4/D5 dropped) — operators manage each creator and its accounts directly. This defers the *merge operation* named in REQ-M3-001 / AC-M3-001; see §2.1. The governing decision is now recorded: **[ADR-0014](../docs/05-decisions/decision-log.md#adr-0014)** (Operator-managed creator identity; merge deferred) — the ADR prerequisite is satisfied.
- **prereq read (Rule 1):** same reading order as Step 1, plus `reviews/SPEC-module3-step1-data-foundation.md` and `reviews/REVIEW-module3-data-foundation-2026-07-06.md` (the foundation this step builds on).

This step delivers **operator-managed creator identity** (the operator curates each `Creator` and its platform accounts by hand — the human is the sole identity authority), the **XMC-001 body** (creator proposals actually create records), and the **first real Module-3 UI**: a Creator profile screen that makes Contact / BrandPreference / CommunicationLog manageable (REQ-M3-002/003/004), following the ADR-0012 hand-built-Livewire convention (`UsersIndex` is the reference implementation). The **dedicated identity-merge feature of REQ-M3-001 / AC-M3-001 is deferred** (§2.1) per the product owner. Campaign/SeedingCampaign/Shipment UI and content matching are **Step 3**; results and full roles are **Step 4** — do not pull that work forward.

---

## 0. Ground truth — read, never restate (Rule 3)

- Field shapes, enums, ownership, and doctrine are canonical exactly where Step 1's spec cited them (§0). This step adds no new canonical facts — it only builds against what Step 1 already made buildable.
- **REQ-M3-001** (`docs/50-modules/module-3-crm-seeding.md#req-m3-001`) and its acceptance criteria AC-M3-001–003 are the authority for identity behavior. Read them in full; this spec does not restate them. Note the deferral in §2.1 — the automatic (AC-M3-003) and dedicated-merge (AC-M3-001) paths are not built in v1.

---

## 1. Current state (starting point — do not rebuild)

Step 1 (`reviews/REVIEW-module3-data-foundation-2026-07-06.md`, ACCEPTED pending review) already shipped:

- Full schema + models + factories + policies for `Contact`, `BrandPreference`, `CommunicationLog` (and every other M3 entity) — **but no Livewire UI and no application-level write services for them yet** (Step 1 was explicitly schema-only: "No new Livewire components in Step 1").
- `App\Modules\CRM\Services\CreatorWriter` — `createCreator()` and `addPlatformAccount()`. **No merge/reassign method exists yet.**
- `App\Modules\CRM\Contracts\CreatorProposals` (XMC-001) bound in `CrmServiceProvider` to `PendingCreatorProposals`, which throws `NotYetImplemented('XMC-001 creator proposal (SVC-CRM)', 'M3 Step 2')`. **This step is that "M3 Step 2."**
- `crm.manage` / `crm.view` permissions and all 13 CRM policies, ready for new UI to authorize against.
- A generic `App\Shared\Audit\AuditLogger` (append-only `audit_logs`, arbitrary `context` jsonb) already used for `UsersIndex` — reusable here.
- A documented (Step-1-flagged, not yet fixed) latent gap: nothing currently enforces "one `PlatformAccount` per `ENUM-Platform` per `Creator`" (`docs/30-data-model/00-data-model.md` line 47 implies this; only `(platform, handle)` is unique in the DB). The operator-driven account panel (§2.4) is where this becomes load-bearing — enforce it there.

---

## 2. Build

### 2.1 Creator identity is 100% operator-managed — no merge feature in v1 (REQ-M3-001, AC-M3-001/003)

**Product-owner decision (2026-07-06): creator identity is entirely a human responsibility in v1.** The operator adds each `Creator` and curates its platform accounts by hand (§2.4); the human alone asserts which accounts belong to one person. Consequently this step builds **none** of the following:

- No **automatic** same-person detection (AC-M3-003) — no matching signals, no `ConfidenceAssessment`-scored queue, no confidence threshold. (This is the earlier D6, now moot — the blocked fact was never needed because the capability isn't built.)
- No **dedicated merge / un-merge feature** (AC-M3-001) — no `mergeCreators()`, no `creator_merges` manifest table, no reversal machinery. (This retires D4 — the "tombstone vs delete the merged-away creator" question — since there is no merge to leave a remnant.)
- No **single-account reassignment tool** (the former D5) — no `reassignPlatformAccount()`.

The operator's levers are ordinary CRUD on the Creator profile (§2.4): create a creator, add/remove its platform accounts, delete a creator that turns out to be a stray duplicate. That covers day-to-day identity curation without a merge subsystem.

**Canonical decision — now recorded in canon.** REQ-M3-001 is titled "cross-platform identity **merge**" and AC-M3-001 ("When an operator confirms the merge … auditable and reversible") + AC-M3-003 (automatic-below-threshold → review queue) are APPROVED acceptance criteria that presuppose a merge capability. Deferring the entire merge feature is a scope reduction against documented, APPROVED criteria — larger than a single AC — so it is captured through the sanctioned mechanism: **[ADR-0014](../docs/05-decisions/decision-log.md#adr-0014)** (APPROVED), which states v1 creator identity is fully operator-managed and defers the automatic (AC-M3-003) and dedicated-merge (AC-M3-001) capabilities of REQ-M3-001 out of v1. Under ADR-0014, AC-M3-003 is satisfied by construction (nothing auto-commits) and AC-M3-001's merge is explicitly deferred, not silently failed. The implementer cites ADR-0014 and does not re-decide it. Doc propagation is already done: the Module 3 spec §2.1 and the req-matrix REQ-M3-001 row both cross-reference ADR-0014 (2026-07-06).

**Practical limitation the operator must know (surface it, don't hide it).** Because a `PlatformAccount` is the anchor M1 attaches monitoring data to (`content_items`, `stories`, `metric_snapshots` all FK `platform_account_id`), there is **no clean way to move an account between two creators** without the (deferred) reassignment tool: deleting and re-adding the account would orphan/cascade its monitoring history. So if an account lands on the wrong creator, the only in-tool fix in v1 loses that account's monitoring history. If this turns out to bite in practice, the deferred `reassignPlatformAccount()` is the smallest thing to add back — note it, don't pre-build it.

XMC-001 (§2.3) therefore attempts **no dedup** — every proposal creates a fresh `Creator`. Auto-proposed creators appear in the normal CRM creators list (§2.4); if one duplicates a manually-curated creator, the operator deletes the stray by hand.

### 2.3 XMC-001 body — real `CreatorProposals` implementation

Replace `PendingCreatorProposals` with a real implementation (new class, e.g. `CreatorProposalIntake`, bound in `CrmServiceProvider::register()` in place of the pending stub):

- `propose(CreatorProposal $proposal): Creator` — per AC-M3-002, **always creates** a new `Creator` (via `CreatorWriter::createCreator()`) and attaches the proposed `PlatformAccount` (via `CreatorWriter::addPlatformAccount()`), using the proposal's `displayName`, platform/handle/bio/externalLinks/followerCount, and mandatory `Provenance`. No dedup against existing creators — identity reconciliation is entirely operator-driven (§2.1), so a duplicate is expected to be resolved by an operator deleting the stray record.
- Keep `IngestedProfileSync` untouched — it remains the separate profile-*sync* half (updating PUBLIC fields on an *existing* account), distinct from proposal-driven *creation*.

### 2.4 Creator profile UI + Contact / BrandPreference / CommunicationLog management (REQ-M3-002/003/004)

The first real Module-3 screens, hand-built Livewire on the TailAdmin shell per ADR-0012, following `UsersIndex` as the reference pattern (searchable/sortable/paginated table + modal create/edit + policy-gated actions + audit on sensitive changes):

- **`crm.creators-index`** — staff-facing list/search of `Creator` records (distinct from the read-only `Monitoring\Livewire\Dashboard\CreatorsIndex`; this one is CRM's own, gated on `crm.view`/`crm.manage`), with create and delete actions (delete is how an operator removes a stray duplicate — §2.1) and the entry point for opening a profile.
- **`crm.creator-profile`** (or similar) — a single creator's detail page with:
  - **Platform accounts panel** — the operator's identity-authority surface (the product-owner decision, §2.1): add / edit / remove the creator's platform accounts (platform + handle + bio + external links) directly, via `CreatorWriter::addPlatformAccount()` plus matching update/detach paths. The human asserts which accounts are this one person — this is the primary way accounts get onto a creator (XMC-001 auto-proposals are the secondary path). **Enforce one account per `ENUM-Platform` per `Creator`** on add (a creator has at most one Instagram, one TikTok, one YouTube — `docs/30-data-model/00-data-model.md` line 47), surfacing a caught error instead of silently creating a second; the global `(platform, handle)` DB uniqueness from Step 1 already prevents two creators claiming the same account. Note the §2.1 limitation: removing an account here deletes it (and would cascade its M1 monitoring history) — there is no move-to-another-creator path in v1. No auto-extraction of accounts/handles — any such affordance renders **"unavailable"** (Rule 8).
  - **Contacts panel** — full CRUD against `ENT-Contact` (AC-M3-004: manual entry). Must include a delete/erase action (DP-005 — Contact stays hard-deletable; this is the UI's GDPR erasure affordance). Any *auto-extraction* affordance must render the literal string **"unavailable"** (Rule 8, DEF-002, AC-M3-005) — do not omit it silently and do not build a working auto-fill.
  - **Brand preferences panel** — CRUD against `ENT-BrandPreference` (preferred/restricted brand **name** lists — plain strings, not FK pickers, per the canonical shape).
  - **Communication log panel** — append/list against `ENT-CommunicationLog` (channel/direction/summary/occurredAt); logs are typically append-mostly (edits should be rare and are not restricted by canon, but do not make it append-only/immutable — that constraint belongs to `review_actions`-style AI-correction trails, not this operator-authored log).
  - **Relationship status** — editable `ENUM-RelationshipStatus` field on the `Creator` itself (module-3 §2.4 ties relationship stage to this enum).
  - **No merge action** — deferred per §2.1; the profile has no merge/reassign controls in v1.

---

## 3. Doctrine to enforce (Rule 9)

- No new support tables this step — the deferred merge subsystem (`creator_merges`, `merge_candidates`) is **not** built (§2.1). This step adds no non-canonical entities.
- All new CRM writes are manual/internal data — no `Provenance` envelope on anything an operator types (only the XMC-001-attached `PlatformAccount` carries Provenance, and that comes from the proposal payload, unchanged from Step 1).
- Every new Livewire action re-authorizes server-side against the relevant policy (`ContactPolicy`, `BrandPreferencePolicy`, `CommunicationLogPolicy`, `CreatorPolicy`, `PlatformAccountPolicy`) — mirroring `UsersIndex`'s "authorize in mount() AND every mutating action" convention. CLIENT_VIEWER must reach none of it.
- Sensitive actions (creator delete, platform-account add/remove, contact delete) are audit-logged via `AuditLogger`, same convention as `UsersIndex`.
- DEF-002's "unavailable" UI rule (Rule 8) applies the moment a Contact screen exists — verify it explicitly, don't assume it falls out for free.

## 4. Decisions

- **D4 / D5 / D6 — all RESOLVED (2026-07-06) by one product-owner call: no merge feature in v1.** Creator identity is 100% operator-managed (§2.1). This drops automatic detection (D6), the dedicated merge/un-merge feature (D4 — the tombstone question is moot with no merge), and single-account reassignment (D5). Nothing about a confidence threshold or a merge manifest is built or specified.
- **One ADR required before Step 2 is marked complete** — records that the automatic (AC-M3-003) and dedicated-merge (AC-M3-001) capabilities of REQ-M3-001 are deferred out of v1 in favor of fully operator-managed identity (§2.1). Same governance pattern as the CLIENT_VIEWER-ADR-before-Step-4 agreement; implementer flags, does not edit canon unilaterally.

## 5. Acceptance criteria for Step 2

- AC-M3-001: **dedicated merge feature deferred out of v1** (product-owner decision §2.1) — an operator manages identity by directly curating a creator's platform accounts, not through a merge/un-merge operation. Must be covered by the §2.1 ADR before this step is complete; do not build a merge to claim the criterion.
- AC-M3-002: `CreatorProposalIntake` (replacing `PendingCreatorProposals`) always creates via `SVC-CRM`; M1/M2 never write `Creator`/`PlatformAccount` directly (unchanged from Step 1, now actually exercised end-to-end).
- AC-M3-003: **automatic path removed from v1** (§2.1) — satisfied **by construction** (nothing auto-commits because nothing auto-merges). Covered by the same §2.1 ADR; do not fake a threshold or a queue.
- AC-M3-004/005: Contact CRUD works; the auto-extraction affordance renders "unavailable", never empty/zero.
- The one-per-platform-per-creator invariant is enforced on the operator's add-account path (§2.4), closing the latent gap flagged in §1.
- Factories/tests exist for every new service method and screen; CRUD screens have feature tests analogous to `UsersCrudTest.php`. (No new tables this step — nothing schema-level to test beyond what Step 1 covered.)
- **Green bar:** full suite, PHPStan level 6, Pint — all with `XDEBUG_MODE=off` (same as Step 1).

## 6. Out of scope (later steps / this step)

- The **entire merge feature** — automatic detection, dedicated merge/un-merge, and single-account reassignment — is **removed from v1** (§2.1). Identity is operator-managed via ordinary CRUD; if a reassignment need bites in practice, `reassignPlatformAccount()` is the smallest thing to add back later.
- Campaigns, seeding campaigns, shipments UI and content-to-campaign matching (Step 3, REQ-M3-005/006/007/008).
- Results (EMV/CPE/CPM), product-level aggregation UI, documents/tasks UI, full roles incl. `CLIENT_VIEWER` (Step 4).
- Any change to `docs/` beyond the one required deferral ADR (§2.1) — if the build surfaces a further data-model gap (e.g. the one-per-platform invariant), STOP per Rule 4 rather than editing canon.

## 7. Handoff

On completion, per the deep-review convention, write `reviews/REVIEW-module3-identity-merge-<YYYY-MM-DD>.md` from `reviews/_TEMPLATE.md` (all checkboxes `[ ]`, `review_status: PENDING_REVIEW`). This step adds no new schema deviations (no new tables). The **required companion doc is the ADR deferring the REQ-M3-001 merge capability** (automatic AC-M3-003 + dedicated-merge AC-M3-001) in favor of operator-managed identity (§2.1) — Step 2 is not "complete" until that ADR exists. Also record the v1 practical limitation (no clean account-reassignment path — §2.1) in project memory so a future step can pick it up.
