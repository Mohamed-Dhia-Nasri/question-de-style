<!--
  Deep-Review Handoff — Module 3 (CRM & Seeding), Step 2: Operator-Managed Identity & Relationship Management
  Written by the IMPLEMENTATION model. Consumed by a SEPARATE review model.
  Build spec: reviews/SPEC-module3-step2-identity-merge.md
-->

# Deep Review — Module 3 Step 2: Operator-Managed Identity & Relationship Management

- **review_status:** REVIEWED (2026-07-07, adversarial multi-agent pass — see `reviews/REVIEW-module3-FINDINGS-2026-07-07.md`)
- **outcome:** ACCEPT WITH FIXES. Step-2 findings: L1 (one-account-per-platform race, app-layer only). No blockers originate in this step.
- **implemented_by:** claude-fable-5
- **implementation_date:** 2026-07-06
- **reviewer:** unassigned
- **deep_review_trigger:** canonical architecture (new ADR-0015, XMC-001 body, first M3 UI), authorization changes (6 new Livewire surfaces + 2 routes), personal-data handling (Contact CRUD incl. GDPR hard-delete) — major feature step

---

## 1. Implementation summary

Built Step 2 per `reviews/SPEC-module3-step2-identity-merge.md`: the **XMC-001 body** (`CreatorProposalIntake` replaces the `PendingCreatorProposals` stub — every M1/M2 proposal creates a *fresh* `Creator` + `PlatformAccount` via `CreatorWriter`, transactional, **no dedup** per ADR-0014) and the **first real Module-3 UI** — a CRM creators index (search by name/handle, relationship-status filter, modal create, confirm-delete as the ADR-0014 stray-duplicate lever) plus a creator profile page with five hand-built Livewire components on the TailAdmin shell (ADR-0012, `UsersIndex` pattern): identity card (editable `ENUM-RelationshipStatus`), platform-accounts panel (operator add/edit/remove; **one account per platform per creator enforced** — the Step-1 latent gap, closed at the app layer in `CreatorWriter`), contacts panel (manual CRUD, GDPR hard-delete, literal **"unavailable"** DEF-002 affordance), brand-preferences panel (string lists), and communication-log panel (append-mostly, no delete). A Rule-4 STOP surfaced mid-build: operator-created `PlatformAccount`s cannot satisfy the mandatory `Provenance` envelope from the frozen all-external SRC registry; the product owner approved **ADR-0015** (internal `SRC-agency-manual-entry` source), written into the decision log + data-source matrix + `SourceRegistry`. **IN:** XMC-001 body, creators index, profile + 4 panels, CreatorWriter curation paths, ADR-0015, tests. **OUT:** any merge/un-merge/reassignment (ADR-0014), schema changes/migrations (none), campaigns/seeding/shipments/matching (Step 3), results/docs/tasks/CLIENT_VIEWER (Step 4).

## 2. Changed files

**Docs (canon — product-owner sanctioned this session)**
- `docs/05-decisions/decision-log.md` — ADR-0015 ledger row + body (internal manual-entry provenance source); ledger intro count 14→15.
- `docs/40-integrations/00-data-source-matrix.md` — `SRC-agency-manual-entry` documented as internal non-provider source (§3 subsection + §4 mapping row), anchored `#src-agency-manual-entry`.

**Services / contracts**
- `app/Platform/Ingestion/SourceRegistry.php` — `AGENCY_MANUAL_ENTRY` constant added to the registry (ADR-0015).
- `app/Modules/CRM/Services/CreatorWriter.php` — added `updateCreator()`, `deleteCreator()` (transactional; deletes only M3-owned profile-managed children; restrict FKs guard everything else), `addManualPlatformAccount()` (stamps ADR-0015 provenance), `updatePlatformAccount()` (re-checks invariants; does **not** re-stamp provenance), `removePlatformAccount()` (savepoint-wrapped); `addPlatformAccount()` now enforces one-per-platform + global (platform, handle) as `PlatformAccountConflict`.
- `app/Modules/CRM/Services/CreatorProposalIntake.php` — NEW: XMC-001 body (AC-M3-002; always-creates, transaction rolls back on handle conflict → no orphan Creator).
- `app/Modules/CRM/Services/PendingCreatorProposals.php` — DELETED (stub replaced).
- `app/Modules/CRM/Contracts/CreatorProposals.php` — docblock updated (no-dedup semantics per ADR-0014).
- `app/Modules/CRM/Exceptions/PlatformAccountConflict.php` — NEW: typed identity-invariant conflict.
- `app/Modules/CRM/CrmServiceProvider.php` — XMC-001 rebound to `CreatorProposalIntake`; 6 new Livewire components registered.

**UI (hand-built Livewire, ADR-0012)**
- `app/Modules/CRM/Livewire/Creators/CreatorsIndex.php` + `resources/views/livewire/crm/creators-index.blade.php` — CRM creators list (crm.view to see, crm.manage to mutate; audit on create/delete).
- `app/Modules/CRM/Livewire/Creators/CreatorProfile.php` + view — identity card incl. `ENUM-RelationshipStatus` select.
- `app/Modules/CRM/Livewire/Creators/PlatformAccountsPanel.php` + view — operator identity-authority surface; conflicts surface as caught validation errors; removal blocked (friendly error) when M1 history anchors to the account; **no merge/auto-detect control exists at all** (ADR-0014).
- `app/Modules/CRM/Livewire/Creators/ContactsPanel.php` + view — manual CRUD; literal "unavailable" auto-extract affordance (DEF-002/AC-M3-005); GDPR hard-delete with identifier-only audit.
- `app/Modules/CRM/Livewire/Creators/BrandPreferencesPanel.php` + view — line-parsed string lists (canonical shape; no brand FKs).
- `app/Modules/CRM/Livewire/Creators/CommunicationLogPanel.php` + view — add/edit/list (channel, direction, summary, occurredAt); no delete.
- `app/Modules/CRM/routes.php` — `/crm/creators` + `/crm/creators/{creator}` under the existing `can:crm.view` group.
- `resources/views/crm/creators.blade.php`, `resources/views/crm/creator-profile.blade.php` — page wrappers.
- `resources/views/crm/index.blade.php` — CRM landing now links to Creators (Step 3/4 areas shown as upcoming).

**Shared**
- `app/Shared/Enums/RelationshipStatus.php` — presentation `label()` added (RoleName convention; values untouched).
- `app/Modules/CRM/Models/Creator.php` — `@property` annotations added (PHPStan; matches sibling models).

**Tests**
- `tests/Feature/Crm/CreatorWriteSeamTest.php` — rewritten: writer curation paths, invariants, provenance-preservation on edit, delete/rollback semantics, roster-blocked delete, real XMC-001 binding.
- `tests/Feature/Crm/CreatorProposalIntakeTest.php` — NEW: proposal creates fresh creator w/ unchanged Provenance; no-dedup; duplicate-handle rollback leaves no orphan.
- `tests/Feature/Crm/{CreatorsCrudTest, CreatorProfileTest, PlatformAccountsPanelTest, ContactsPanelTest, BrandPreferencesPanelTest, CommunicationLogPanelTest}.php` — NEW: rendering, search/sort/filter/pagination, validation, audit events, GDPR delete, DEF-002 literal "unavailable", crm.view/crm.manage split incl. direct-mutator bypass attempts (form-open actions skipped via client-settable state).
- `tests/Unit/ValueObjects/EnvelopeTest.php` — registry guard updated: 11 external + 1 internal (ADR-0015) = 12.

## 3. Canonical documents relied upon

- `docs/50-modules/module-3-crm-seeding.md` (`module-3-crm-seeding`) — REQ-M3-001 §2.1 v1 scope, REQ-M3-002/003/004, AC-M3-001..008, XMC-001 contract table.
- `docs/05-decisions/decision-log.md` (`decision-log`) — **ADR-0014** (operator-managed identity; merge deferred — governs everything absent), ADR-0012 (hand-built Livewire), ADR-0008 (provenance/confidence doctrine), ADR-0001 (frozen stack), **ADR-0015** (added this step, product-owner approved).
- `docs/30-data-model/00-data-model.md` (`DOC-DataModel`) — ENT-Creator/PlatformAccount/Contact/BrandPreference/CommunicationLog shapes; §1 "one per ENUM-Platform presence" (the enforced invariant); Provenance envelope shape.
- `docs/00-meta/03-glossary.md` (`03-glossary`) — ENUM-Platform, ENUM-RelationshipStatus (closed sets mirrored in validation).
- `docs/70-shared/00-ownership-matrix.md` (`OWNERSHIP-MATRIX`) — M3 write-owns Creator/PlatformAccount/Contact/BrandPreference/CommunicationLog; M1-owned tables never written.
- `docs/20-cross-cutting/00-data-principles.md` — DP-001 (tier travels with follower_count in UI), DP-002 (provenance mandatory — the ADR-0015 trigger), DP-005 (GDPR hard-delete).
- `docs/20-cross-cutting/01-deferred-register.md` — DEF-002 + the unavailable-never-empty rule (contacts panel affordance).
- `docs/40-integrations/00-data-source-matrix.md` — closed SRC registry (amended per ADR-0015).
- `docs/AGENTS.md` (`AGENTS`) — Rules 1–11; Rule 4 STOP exercised for the provenance gap.

## 4. Known deviations / open conflicts

- **ADR-0015 is new canon written this session** (product-owner approved after a Rule-4 STOP). Verify its body, the data-source-matrix amendment, and `SourceRegistry::AGENCY_MANUAL_ENTRY` agree; the decision: manual stamp marks *record origin* only — never re-stamped onto provider-fetched rows (an operator edit keeps provider provenance; the change is audit-logged instead).
- **Accounts-panel "unavailable" affordance intentionally ABSENT.** Spec §2.4 says any auto-extraction affordance must render "unavailable", but ADR-0014's consequence states this deferral produces **no** unavailable surface (absent feature, not unavailable data). Canon wins: the panel has no auto-detect control at all (pinned by test). The contacts panel affordance (mandated by DEF-002/AC-M3-005) **is** rendered.
- **Restrict-FK semantics vs spec wording.** Spec §2.1/§2.4 says account removal "would cascade its M1 monitoring history"; the actual Step-1 schema **restricts** (content_items/stories/metric_snapshots FKs). Implemented as: removal/deletion is *refused* with a friendly error when monitoring history (or roster membership) exists — safer than the spec's parenthetical, no M1-owned rows are ever touched. Flag if the reviewer reads canon differently.
- **`deleteCreator()` scope choice (unspecified by canon):** transactionally deletes the four profile-managed child types (platform accounts, contacts, brand preferences, communication logs) then the creator; shipments/documents/tasks/monitored-subjects/M1 data block the delete via existing restrict FKs. Chosen as the minimal stray-duplicate lever; reviewer judgment invited.
- **`direction` validated as app-layer closed set** `inbound|outbound` (data-model note names exactly these; no glossary enum exists — same flagged class as Step 1's app-layer choices). `channel` stays free-text per canon.
- **Audit superset:** spec §3 requires audit on creator delete, account add/remove, contact delete; also logged: `creator.created`, `platform_account.updated` (UsersIndex convention). Flag if considered noise.
- **Automated adversarial review partially completed.** A 20-agent review (6 dimensions × adversarial verifiers) ran post-build: canon/scope/tests dimensions completed and all 7 findings were fixed (see §7 scenario 6); the **ownership, security, and correctness dimension sweeps did not complete** (provider session limits). Those areas got implementer self-review only — weight the §7 scenarios accordingly.

## 5. Tests & checks

- **Executed (passing):**
  - `XDEBUG_MODE=off php artisan test` — **451 passed (1803 assertions)**; 62 tests added since Step 1's 389; full-suite regression clean.
  - `XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=1G` — level 6, **no errors**.
  - `XDEBUG_MODE=off vendor/bin/pint --test` — **passed**.
- **Executed (failing / skipped):** none.
- **Not executed:** docs linter as a program (spec-only; `docs/_lint/check-docs.md` self-check done by hand for the two amended docs — anchors, ID declaration, no restated enums/fields, frontmatter untouched); manual browser walkthrough (feature tests render full pages via HTTP + Livewire harness).
- **External integrations not verified:** none — no external surface; XMC-001 has no production caller yet (M1/M2 wire-up is future work).

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

1. **ADR-0015 legitimacy + provenance honesty** — *Focus:* `docs/05-decisions/decision-log.md#adr-0015`, `docs/40-integrations/00-data-source-matrix.md`, `SourceRegistry`, `CreatorWriter::addManualPlatformAccount()/updatePlatformAccount()`. *Do:* verify the internal source never lands on provider-fetched rows (edit path preserves origin provenance — pinned in `PlatformAccountsPanelTest` + `CreatorWriteSeamTest`) and that the frozen-external-stack rationale (ADR-0001/DP-006) genuinely survives the amendment. *Expected:* manual stamp only on operator-created rows. *Risk if wrong:* provenance doctrine (the platform's core trust mechanism) is quietly diluted.
2. **Authorization matrix incl. mutator bypass** — *Focus:* all 6 Livewire components + `routes.php`. *Do:* as CLIENT_VIEWER hit both routes and mount every component; as a crm.view-only user invoke every *persisting* method directly with client-set state (Livewire exposes public props/methods). *Expected:* 403 everywhere; DB untouched. *Risk if wrong:* privilege escalation into CRM writes / GDPR erasure. **Note:** the automated security sweep did not complete — this scenario deserves the most reviewer time.
3. **Write-seam sanctity** — *Focus:* ownership matrix; grep for `Creator`/`PlatformAccount` persistence outside `CreatorWriter`/`IngestedProfileSync`. *Do:* confirm the Livewire components never write those models directly and that `deleteCreator()` touches only M3-owned rows (M1 data protected by restrict FKs, verified by rollback test). *Expected:* SVC-CRM remains the only writer. *Risk if wrong:* ownership-matrix violation. **Note:** automated ownership sweep did not complete.
4. **One-per-platform invariant under concurrency** — *Focus:* `CreatorWriter::assertPlatformFree()/assertHandleFree()`. *Do:* note the check-then-insert is not race-proof for *platform-per-creator* (no DB constraint exists — Step-1 flagged gap deliberately not schema-fixed this step per "nothing schema-level"); the global (platform,handle) unique IS the DB backstop for handles. *Expected:* accept app-layer enforcement or flag for a Step-3 unique index `(creator_id, platform)` via doc amendment. *Risk if wrong:* a creator ends up with two Instagrams under concurrent operators.
5. **XMC-001 semantics** — *Focus:* `CreatorProposalIntake`, `CreatorProposalIntakeTest`. *Do:* verify always-creates (no dedup), Provenance passthrough, and duplicate-handle rollback (no orphan creator); consider what M1/M2 callers must handle (`PlatformAccountConflict`). *Expected:* matches AC-M3-002 + ADR-0014. *Risk if wrong:* silent identity reconciliation creeps back in, or proposals leave debris.
6. **Post-build review findings were fixed correctly** — *Focus:* this session's 7 adversarial-review findings: (a) accounts-panel unavailable affordance removed (canon over spec), (b) edit no longer re-stamps provenance, (c) ADR-0015 consequences made self-consistent, (d) DP-001 miscite fixed, (e) this handoff written, (f) mutator-level authz tests added, (g) `occurred_at` persistence pinned. *Do:* verify each fix and that the two mutation-probe edits (`MUTATION-TEST` comments once at `CreatorsIndex::save`/`ContactsPanel::save`) are fully reverted — `grep -rn "MUTATION-TEST" app/ tests/` must be empty. *Risk if wrong:* a review artifact ships as product code.
7. **GDPR erasure completeness** — *Focus:* `ContactsPanel::delete()`, `ContactGdprDeletionTest` (Step 1), `ContactsPanelTest`. *Do:* delete a fully-populated contact; verify hard-delete and that neither the audit `context` nor any other table retains the personal data. *Expected:* identifier-only audit trail. *Risk if wrong:* GDPR erase obligation unfulfillable (DP-005).

## 8. High-risk areas

- `app/Modules/CRM/Services/CreatorWriter.php` — all identity invariants + delete semantics concentrate here; the one-per-platform check is app-layer only (no DB constraint).
- `app/Modules/CRM/Livewire/Creators/PlatformAccountsPanel.php` — the identity-authority surface: authz, conflict surfacing, provenance handling, and M1-history protection all meet in one component.
- `docs/05-decisions/decision-log.md` (ADR-0015) — new canon written under implementation pressure; wrong wording here becomes binding doctrine.
- `app/Modules/CRM/Services/CreatorProposalIntake.php` — the contract M1/M2 will call in Step 3; its conflict/rollback behavior propagates into two other modules.

---

## 9. Reviewer findings

> Filled by the REVIEW model. One checkbox per finding; check when resolved/dispositioned.

- [ ] —

## 10. Review sign-off

- **Reviewer:** —
- **review_status → REVIEWED on:** —
- **outcome:** —
- **Summary:** —
