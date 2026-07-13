# REVIEW TODO — Multi-Tenant Foundation (ADR-0019, SaaS pivot Prompt 1)

Date: 2026-07-11. Scope: the uncommitted diff implementing the multi-tenant SaaS foundation — canonical `ENT-Tenant`, `users.tenant_id`, tenant ownership keys + composite tenant FKs across every business table, centralized `TenantContext`, tenant-scoped natural keys, tenant-aware analytics star schema, factories/tests support, and the ADR-0019 documentation set.

Verification state at handoff: full suite **751 passing** (727 baseline + 24 new tenancy tests; `XDEBUG_MODE=off vendor/bin/phpunit`), PHPStan at the pre-existing 12-error `DemoDataSeeder` baseline, migrations + founding-tenant backfill + `qds:refresh-rollups` **proven on the populated local dev DB** (306 creators, 10k append-only snapshots; zero NULL tenant_ids; owner = earliest active ADMIN).

> **Adversarial review status:** a six-lens find→verify workflow (context-leakage, migration-correctness, ownership-stamping, scope-correctness, integrity-coverage, test-fidelity) was RUN at build time (18 agents; 10 confirmed / 2 refuted findings; migration-correctness and integrity-coverage lenses came back clean). All confirmed findings were FIXED in-session:
> 1. **[critical] Middleware ordering** — SetTenantContext ran after SubstituteBindings, so route-model binding resolved tenant-owned models unscoped (cross-tenant id resolved instead of 404). Fixed via `prependToPriorityList` pinning EnsureUserIsActive → SetTenantContext → SubstituteBindings; regression test `CrossTenantIntegrityTest::test_route_model_binding_is_tenant_scoped`.
> 2. **[major] Sync-queue double pop** — a failing job fires two terminal queue events, so one context push got two pops and the dispatcher's context was nulled. Fixed with a per-job WeakMap dedupe in TenancyServiceProvider + no-op on empty-stack pop; regression tests in TenantOwnershipTest (fail()-and-return + escaping-exception paths).
> 3. **[major] RefreshCampaignContentJob permalink collapse** — keyBy(permalink) dropped all but one tenant's row for a shared public post; now groupBy + fan-out to every matching tenant row + URL dedupe.
> 4. **[major] Queue-propagation test passed for the wrong reason** (sync driver shares ambient context) — rewritten against the database driver with a real `queue:work --once` pass + payload assertion.
> 5. **[minor] ExportManager::pruneExpired** audit rows lost tenant attribution — now runAs the job row's tenant.
> 6. **[minor] ResolvesTenant::tenantFromParent dead code + silent stray-tenant fallback** — removed; `defaultTenantId()` now throws MissingTenantContext when no context is bound.
> 7. **[minor] test_explicit_tenant_id_wins_over_context was vacuous** — now supplies an explicit tenant differing from the context.
> 8. **[major, DEFERRED to Prompt 2 by ADR-0019]** Model policies gate on permission only and never compare the model's tenant to the actor's — mitigated for authenticated bound routes by fix 1; per-policy tenant guards (and the auth-less signed story-media stream route) belong to the hard-enforcement phase. **This is the first thing Prompt 2 must close.**
>
> The checklist below remains for the SEPARATE deep-review model per the handoff convention — re-verify independently, especially the ⚠ lenses.

## What was built (file map)

**Core tenancy** (`app/Shared/Tenancy/`, `app/Models/Tenant.php`):
- `Tenant` model (`tenants`: id, name, owner_user_id → users, NO ACTION; owner membership DB-pinned via `tenants_owner_same_tenant_fk (owner_user_id, id) → users (id, tenant_id)`).
- `TenantContext` — scoped container binding (per-request/per-job); `runAs()` restores; job push/pop stack for sync-queue safety.
- `TenantScope` + `BelongsToTenant` — creating-hook stamp from context; query scope active only when a context is set (platform mode = unscoped, hard enforcement deferred to Prompt 2).
- `TenancyServiceProvider` — `Queue::createPayloadUsing` tenant propagation + JobProcessing/Processed/Failed/ExceptionOccurred restore.
- `SetTenantContext` middleware (web group, after `EnsureUserIsActive`).
- `TenantProvisioner` — atomic tenant+owner (ADMIN role) creation.

**Migrations**: `2026_07_11_100000` (tenants), `100001` (users.tenant_id + founding-tenant backfill "Question de Style" + owner assignment), `100002` (tenant_id on 33 business tables via catalog-DEFAULT backfill — append-only triggers never fire; tenant-scoped uniques for platform_accounts/content_items/stories/hashtag_lists/EMV incl. one-ACTIVE-per-tenant; `UNIQUE (id, tenant_id)` on 15 parents; ~60 composite `(fk, tenant_id)` FKs), `analytics/2026_07_11_200001` (tenant_id on 5 facts + 7 entity dims; all 9 rollup MVs rebuilt tenant-grouped with tenant-leading unique indexes).

**Ownership map**: tenant-owned = all M1/M2/M3 entities, enrichment registers, export_jobs, monitoring_plan_settings; GLOBAL = provider telemetry registers (provider_calls, provider_health_states, ingestion_alerts, quarantined_records, provider_response_samples, ingestion_cycles), analytics watermarks/refreshes, spatie definitions, dim_date + enum dims; audit_logs = nullable tenant.

**Plumbing**: persisters tenant-scope natural-key lookups + stamp from the account row; ingestion/enrichment jobs `runAs` the aggregate root's tenant; snapshot scheduler stamps from account/content rows; exports restore context from job payload with ExportJob-row fallback; new object-storage writes prefixed `tenants/{id}/…` (exports, documents, story media, GDPR dossiers; retention sweeps cover both layouts); AuditLogger stamps nullable tenant; DemoDataSeeder binds the founding tenant.

**Tests**: `tests/Feature/Tenancy/{TenantFoundationTest,TenantOwnershipTest,TenantUniquenessTest,CrossTenantIntegrityTest}.php`; `tests/TestCase.php` default tenant per test + `makeTenant`/`actingAsTenant`/`withTenant`/`makeTenantPair` helpers.

**Docs**: ADR-0019 (decision-log), ENT-Tenant + tenancy notes (data model), tenancy preamble + ENT-Tenant row (ownership matrix), §4.3 tenant context (architecture), GL-Tenant (glossary), tenancy note (analytics model), entity count 28→29 (index).

## Review checklist (for the independent deep-review pass)

### Context lifecycle ⚠
- [ ] TenantContext cannot leak across jobs on a long-running worker (scoped flush + push/pop; check JobFailed + JobExceptionOccurred double-fire semantics).
- [ ] Sync-queue nested dispatch restores the dispatcher's context in every exception path.
- [ ] Middleware ordering: no code path resolves tenant-scoped models before SetTenantContext runs with a DIFFERENT tenant's context active.

### Migration & backfill
- [ ] Founding-tenant backfill assigns every pre-existing row; loud abort when rows exist with no tenant.
- [ ] Append-only tables (metric_snapshots, review_actions, emv_results, 5 fact tables incl. partitions) backfilled without firing triggers.
- [ ] down() restores the original constraint set; migrate:rollback + migrate round-trips.
- [ ] All generated constraint names < 63 bytes and match what Laravel created.

### Ownership stamping ⚠
- [ ] Every create path stamps the aggregate root's tenant, not ambient context (persisters, snapshots, enrichment, matching, exports, uploads).
- [ ] XMC-001 creator proposals always have a derivable tenant.
- [ ] Pivot attach paths (Livewire panels, ShipmentContentWriter) always carry tenant_id.

### Scope semantics ⚠
- [ ] firstOrCreate/updateOrCreate natural-key lookups behave under scope (no cross-tenant steal, no scoped-miss → unique violation).
- [ ] Auth paths (Fortify authenticateUsing, password broker, EnsureUserIsActive) unaffected by an active scope.
- [ ] Scheduled commands spanning tenants write correctly per row without ambient context.

### Structural integrity
- [ ] No FK between tenant-owned tables lacks a composite tenant FK; every remaining global unique is deliberately global (users.email) or FK-scoped.
- [ ] Polymorphic/no-FK references (review_actions.reviewable, audit subject, provider_calls.platform_account_id) cannot silently cross tenants in a way that matters for Prompt 2.

### Test fidelity
- [ ] expectException(QueryException) tests still fail on the INTENDED constraint (not the tenant NOT NULL / composite FK).
- [ ] A test proves tenant-owned creation fails without context AND without the events-suppressed path.
- [ ] Tenant A/B fixtures are truly isolated (context switching, not manual tenant_id passing).

### Deferred (Prompts 2–4 — do NOT flag as findings)
Hard read-path enforcement (dashboards/RollupReader unscoped), Stripe/seats, invitations/self-service signup, per-tenant provider telemetry + cost attribution, per-tenant cadence resolution in shared monitoring cycles, tenant lifecycle tooling (offboarding/export), cross-tenant isolation claims (not made — proven adversarially in Prompt 2).
