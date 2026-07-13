# REVIEW TODO — Stripe billing, seats & team invitations (ADR-0021, SaaS pivot Prompt 3)

Date: 2026-07-12. Scope: the uncommitted diff implementing SaaS billing on top of the
multi-tenant foundation (ADR-0019) and hard isolation (ADR-0020): tenant-as-Stripe-customer,
config-synced subscription plan catalog, webhook-driven subscription lifecycle, seat limits
with row-lock concurrency enforcement, hashed-token team invitations, owner-only billing
surfaces, and the `subscribed` product gate.

Verification state at handoff: full suite **866 passing** (`XDEBUG_MODE=off vendor/bin/phpunit`;
was 779 before this diff — 87 new billing tests), PHPStan at the pre-existing 12-error
`DemoDataSeeder` baseline, Pint clean.

> **Adversarial review status: RAN — 5 lenses (webhook-auth, seat-race, invitation-token,
> cross-tenant-billing, enforcement-bypass), each attacked at high effort, candidate findings
> independently verified.** 4/5 invariants held. Results:
> - **1 CONFIRMED (fixed):** the `subscribed` gate did not re-apply on Livewire's global
>   `/livewire/update` endpoint, so a lapsed tenant with a page still open could keep firing
>   mutating component actions (own-tenant only; latent — enforcement defaults off). **Fixed**
>   by registering `EnsureTenantSubscribed` as Livewire persistent middleware in
>   `BillingServiceProvider::boot()` (re-applies only for components whose original route
>   carried `subscribed`, so billing-recovery components stay reachable). Test:
>   `test_the_subscription_gate_is_a_livewire_persistent_middleware`.
> - **1 REFUTED:** claimed seat-race TOCTOU on the UsersIndex edit path — refuted because
>   Eloquent's `save()` writes only dirty columns, and the same stale read that skips
>   `reserve()` also leaves `active` non-dirty, so the concurrent deactivation is never
>   overwritten. No bypass.
> - **1 LOW (fixed):** case-sensitive email-taken check at acceptance could let a mixed-case
>   `Foo@x.com` account and a lower-cased `foo@x.com` invitation both exist. **Fixed** with a
>   `lower(email)` comparison in `TeamInvitationAccepter`. Test:
>   `test_acceptance_email_taken_check_is_case_insensitive`.
> - **1 NOTE (fixed):** a missing `event.created` stamped a 1970 watermark on a new
>   subscription's first sync — now falls back to `now()`.
> - **Notes accepted (documented, not fixed):** invitation token in the URL path (mirrors
>   `reset-password/{token}`, single-use + short-lived); `TenantProvisioner` creates the owner
>   outside `reserve()` (necessary — a new tenant has no subscription so its limit is 0);
>   `EnsureTenantSubscribed` fails open on a null context (unreachable for authed product
>   routes — `web` group binds context first; verified via `route:list`); `checkout.session`
>   trusts Stripe's own subscription↔customer invariant (not attacker-reachable).
>
> The `broken=true` webhook/cross-tenant lenses ultimately returned only note-severity items;
> no cross-tenant leak, no forgeable webhook attribution, no invitation forgery/replay found.

## Invariants
> 1. `active_members_count <= seat_limit` under ANY interleaving — every seat-consuming
>    mutation (create active user, reactivate, bulk-activate, invitation acceptance)
>    serializes on `SELECT … FOR UPDATE` of the tenant row inside `SeatLimiter::reserve()`.
> 2. Billing state is written ONLY by signature-verified, deduplicated, order-guarded Stripe
>    webhooks; tenants resolve ONLY via `tenants.stripe_customer_id` (never payload metadata).
> 3. Tenant A can never reach Tenant B's billing portal, checkout, subscription, seats, or
>    invitations. Modelled attacker: a fully-privileged tenant ADMIN, plus an unauthenticated
>    guest holding a leaked/forged invitation link or webhook payload.
> 4. No subscription state ever deletes or mutates tenant business data.

## What was built (file map)

**Billing module** (`app/Modules/Billing/`):
- `BillingServiceProvider.php` — routes, Livewire registration, `TeamInvitationPolicy`,
  and the `billing.manage` **defined gate** (true only for `tenants.owner_user_id` — owner is
  an attribute, not a role; deliberately NOT a PermissionsCatalog permission. Spatie's
  `Gate::before` returns null for unknown ability names, so the definition is reached).
- `Models/SubscriptionPlan.php` — GLOBAL catalog (no `BelongsToTenant`), synced from
  `config/billing.php` by `Services/SubscriptionPlanSync.php` (+ `qds:billing-sync-plans`
  command, also called by `DatabaseSeeder`). `isPurchasable()` = active AND has a price id.
- `Models/TenantSubscription.php` — tenant-owned mirror of Stripe's subscription; only writer
  is the webhook synchronizer. `liveFor(int $tenantId)` is **unscoped but tenant-parameterised**
  (callers: middleware via context id, `SeatLimiter` via locked tenant, pages via user tenant).
  One live row per tenant enforced by partial unique index
  `tenant_subscriptions_one_live_index` — its `WHERE status NOT IN ('CANCELED','INCOMPLETE_EXPIRED')`
  list must stay in sync with `SubscriptionStatus::terminal()`.
- `Models/TeamInvitation.php` — stores only the SHA-256 `token_hash` of the 64-char token;
  composite tenant FKs to inviter/accepted user; partial unique = one PENDING per
  (tenant, lower(email)).
- `Models/StripeEvent.php` — global append-only webhook idempotency ledger (id + type only,
  never payloads).
- `Http/StripeClient.php` — SDK-free client on the `Http` facade (the ApifyClient doctrine):
  create customer (deterministic `Idempotency-Key qds-tenant-{id}-customer`), create Checkout
  session, create Billing-Portal session, get subscription. Failures → sanitized
  `StripeApiException` (status + Stripe error code only, never bodies/secrets).
- `Http/StripeWebhookSignature.php` — offline HMAC verification of `Stripe-Signature`
  (t + multiple v1, constant-time compare, tolerance `services.stripe.webhook_tolerance_seconds`).
- `Http/Controllers/StripeWebhookController.php` — registered **outside the web group** (no
  session/CSRF; signature is the sole credential). Dedup INSERT + processing share ONE
  transaction: duplicate → 200 'duplicate'; processing failure → rollback (ledger row removed)
  + 500 so Stripe's retry is not swallowed.
- `Services/SubscriptionSynchronizer.php` — the only `TenantSubscription` writer. Tenant
  resolution ONLY via `stripe_customer_id`; out-of-order guard on `event.created` vs
  `last_stripe_event_at` (strictly-older skipped; equal applies — sync is idempotent); unknown
  customer/price/status log-and-acknowledge (never retry-able errors); a new live subscription
  supersedes a stale live row (old → CANCELED); writes under `runAs($tenant)` (ADR-0020 §7);
  `invoice.payment_failed` / recovery audit-logged with identifiers only.
- `Services/SeatLimiter.php` — the seat mechanism. Seat model: every ACTIVE user consumes one
  seat incl. the owner; inactive users and pending invitations consume none. `limitFor`:
  enforcement off → null (unlimited); enforced with no access-allowing live subscription → 0;
  else `seats_override ?? plan.max_seats`. `reserve()`: transaction → tenant row
  `lockForUpdate` → refuse if already over (downgrade freeze) → mutation → recount → refuse
  (rollback) if over. Downgrades never remove members.
- `Services/BillingManager.php` — owner actions: `ensureCustomer` (row lock + idempotency key
  → double-click cannot mint two customers), `checkoutUrl`, `portalUrl` — customer id always
  from the tenants row, never input.
- `Services/TeamInvitationIssuer.php` / `TeamInvitationAccepter.php` — issue (auto-revokes
  expired pending duplicates; on-demand `TeamInvitationNotification` carries the only plaintext
  token) and accept (guest flow: re-load invitation `FOR UPDATE` **inside** `reserve()`'s
  tenant lock → single-use + expiry + revocation decided atomically; account created with the
  INVITED email — no email input exists; global email uniqueness re-checked; `runAs` stamps the
  inviting tenant; `Auth::login` + session regenerate on success).
- `Http/Controllers/InvitationAcceptController.php` — `reset-password/{token}`-shaped guest
  routes; signed-in sessions rejected; all failure modes render `billing.invitation-invalid`.
- `Http/Middleware/EnsureTenantSubscribed.php` (alias `subscribed`) — ACTIVE/TRIALING/PAST_DUE
  pass; everything else redirects to `account.index` (JSON → 402). Gated on
  `config('billing.enforced')` (env `QDS_BILLING_ENFORCED`, default **false** — founding tenant
  predates billing).
- `Policies/TeamInvitationPolicy.php` — `users.manage` only; tenancy backstopped by
  `TenantIsolationGate`.

**Shared enums**: `app/Shared/Enums/SubscriptionStatus.php` (Stripe-canonical states,
`fromStripe()`, `allowsProductAccess()`, `terminal()`), `BillingInterval.php`.

**Schema** (`database/migrations/2026_07_12_2100*`): `subscription_plans` (global),
`tenants.stripe_customer_id` (nullable unique, NOT fillable), `tenant_subscriptions`
(tenant-owned + one-live partial index), `team_invitations` (tenant-owned + composite user FKs
+ pending-email partial index), `stripe_events` (global). Factories for the first three
(all declare `protected $model` — module namespaces defeat factory name-guessing).

**Enforcement wiring**: `bootstrap/app.php` (alias), `subscribed` added to: dashboard +
reports groups (`routes/web.php`), CRM group + document download, Monitoring group + export
download + story-media stream, Discovery group. Account/billing/team/auth/invitations/webhook
deliberately NOT gated (recovery must stay reachable).

**UsersIndex integration** (`app/Modules/CRM/Livewire/Users/UsersIndex.php`): `save()` wraps
create-active and reactivation in `reserve()` (violations → validation key `seats`);
`bulkSetActive(true)` is all-or-nothing under one `reserve()` (violation → error toast);
deactivation never gated.

**UI**: `/account` (AccountOverview: plan, status, seats X/Y, over-limit + blocked banners),
`/account/billing` (BillingManage: plan cards → Checkout, portal button, not-configured state),
invitations panel on `/admin/users` (TeamInvitationsPanel: seat banner, invite, revoke),
sidebar "Account" section (Account / Billing via `@can('billing.manage')`), guest
invitation-accept/invalid views.

**Config**: `config/billing.php` (enforced flag, invitation expiry, plan catalog from env),
`config/services.php` `stripe` block, `.env.example` billing section.

**Tests**: `tests/Feature/Billing/{SubscriptionLifecycleTest,StripeWebhookHttpTest,
SeatEnforcementTest,SeatConcurrencyTest,TeamInvitationTest,BillingAuthorizationTest,
SubscriptionEnforcementTest}.php` + `tests/Support/InteractsWithStripe.php` (locally-computed
HMAC signatures; `Http::fake` only — zero live Stripe). `SeatConcurrencyTest` uses
**DatabaseTruncation + a second raw PDO connection** to prove lock serialization and the
recount-after-commit interleaving; its `tearDown` truncates committed fixtures so the
transaction-wrapped classes that follow keep their absolute-count assertions.
`TenantIsolationArchitectureTest` skip-list gained `invitations` (the reset-password-token
precedent).

**Docs**: ADR-0021 (decision-log + ledger + count sentence), data-model (ENT-Tenant column +
3 new entities + stripe_events register, 29→32), ownership matrix (4 rows + Billing module),
glossary (ENUM-SubscriptionStatus, ENUM-BillingInterval, GL-Seat), index counts,
system-architecture §4.3 stale-paragraph fix + billing seam.

## Review checklist (for the independent deep-review pass)

### Webhook security ⚠
- [ ] Re-derive `StripeWebhookSignature::verify` against Stripe's spec: HMAC input is
      `"{t}.{raw body}"`; multiple `v1` entries; constant-time compare; tolerance applied on
      `abs(now - t)`. Hunt for header-parsing bypasses (`t=` duplicates, whitespace, `v0`).
- [ ] Verify the dedup transaction claim in `StripeWebhookController`: duplicate delivery of an
      in-flight event blocks on the unique index and resolves to 'duplicate' after commit; a
      FAILED run leaves NO `stripe_events` row (rollback) and answers 500.
- [ ] Confirm the route is outside the `web` group in the compiled route table
      (`php artisan route:list`) — no session, no CSRF, global middleware only.
- [ ] Audit `SubscriptionSynchronizer` tenant resolution: grep for any read of
      `metadata` used for resolution (must be none). Check `checkout.session.completed`: tenant
      comes from the SESSION's customer while the row syncs from the FETCHED subscription —
      confirm a subscription belonging to a different customer cannot be attributed to the
      session's tenant (or that the mismatch is impossible/benign).
- [ ] Out-of-order guard: strictly-older skipped, equal-timestamp applied — convince yourself
      idempotent sync makes equal-ts application safe (created+updated share a second at checkout).
- [ ] `supersedeLiveSubscription` only fires inside the resolved tenant's own rows — confirm no
      cross-tenant reach.

### Seat concurrency ⚠
- [ ] Re-audit that EVERY seat-consuming path goes through `SeatLimiter::reserve()`: UsersIndex
      create/reactivate/bulk-activate, invitation acceptance. Then hunt for paths that don't:
      `TenantProvisioner::create` (pre-billing owner bootstrap — is it reachable while enforced?),
      GDPR flows, seeders (dev-only), future Fortify features.
- [ ] Verify `reserve()`'s lock actually serializes: `SeatConcurrencyTest` holds the tenant row
      lock on a second PDO connection (lock_timeout → SQLSTATE 55P03) and proves the
      recount-after-commit refusal (4+1 committed by the "winner", loser sees 6>5 and rolls back).
      Confirm the test is not tautological (it inserts via raw SQL, not through the limiter).
- [ ] Downgrade freeze: over-limit tenants are refused ANY reserve() mutation (pre-check), and
      nothing auto-deletes members on plan change (SubscriptionLifecycleTest downgrade test).
- [ ] Lock ordering: reserve() locks `tenants` then touches `users`/`team_invitations`; the
      accepter locks the invitation row INSIDE the tenant lock. Confirm no other code path locks
      these tables in the opposite order (deadlock).
- [ ] `limitFor()` is computed inside the transaction but reads `TenantSubscription::liveFor`
      without a lock — a plan change committing mid-reserve could change the limit between check
      and commit. Assess: worst case is one mutation judged against the just-replaced limit;
      is that acceptable drift or does the subscription row need `FOR SHARE`?

### Invitation token security ⚠
- [ ] Token lifecycle: 64-char `Str::random` (≈380 bits alphanumeric), only SHA-256 stored,
      plaintext only in the notification. Grep logs/audit contexts for accidental token capture.
- [ ] Single-use under concurrency: two simultaneous accepts of the SAME token both pass
      `find()`, serialize on the tenant lock, second re-load sees `accepted_at` → invalid.
      Confirm the re-load truly happens inside `reserve()`'s transaction with `lockForUpdate`.
- [ ] Email binding: acceptance takes NO email input; account gets `invitation.email` verbatim.
      Case-sensitivity: panel lowercases before storing; `users.email` uniqueness is exact-match
      — probe `Foo@x.com` user vs `foo@x.com` invitation at the acceptance email-taken check
      (Fortify lowercases usernames at login; UsersIndex does not lowercase on create).
- [ ] Role containment: `Rule::in(RoleName::staff())` — CLIENT_VIEWER and garbage rejected;
      tampered Livewire `role` property cannot bypass (validation runs server-side per call).
- [ ] Guest POST is CSRF-protected (web group), session regenerated after `Auth::login`.
- [ ] The architecture-test skip (`invitations` URI prefix) exempts ONLY the two guest
      invitation routes — confirm no other route shares the prefix.

### Cross-tenant billing isolation ⚠
- [ ] `billing.manage` gate: self-relative (owner of one's OWN tenant); `users.tenant_id` is
      non-mass-assignable and stamped server-side — confirm no forgery path.
- [ ] Portal/checkout customer id always read from the actor's own `tenants` row; Livewire
      components derive the tenant from `auth()->user()->tenant`, never from input.
- [ ] Middleware order: `SetTenantContext` (web group, pinned before `SubstituteBindings`) vs
      `subscribed` (route middleware) — verify with the compiled middleware list that context is
      always bound before `EnsureTenantSubscribed` runs, for every gated route. A null context
      passes OPEN by design (guests are stopped by `auth`) — confirm no authed route reaches the
      middleware without context.
- [ ] Panel/account/billing queries: invitations via the tenant-scoped default query;
      subscription reads via `liveFor((int) $tenant->id)` — audit every caller's tenant source.

### Lifecycle enforcement
- [ ] Route-table sweep: every product route carries `subscribed`; account/billing/admin-users/
      auth/invitations/webhook/health do not. Pay attention to the two signed download routes
      and `monitoring.stories.media-url`.
- [x] ~~Known accepted gap: `/livewire/update` is not `subscribed`-gated~~ — **FIXED** during
      adversarial review: `EnsureTenantSubscribed` is now a Livewire persistent middleware
      (`BillingServiceProvider::boot()`), so Livewire re-applies it on `/update` for components
      whose original route carried `subscribed`. Re-verify: billing-recovery components
      (BillingManage/AccountOverview/TeamInvitationsPanel, rendered on ungated routes) stay
      reachable for a lapsed tenant; a mutating CRM/Monitoring action does not.
- [ ] `CLIENT_VIEWER` of a lapsed tenant dead-ends (redirect → /account → 403). Accepted per
      ADR-0016 (no client accounts in v1) — confirm no error-page loop.
- [ ] Data preservation: no deletion path exists on any subscription state (grep the module for
      `delete`/`truncate` — only invitation revocation stamps and factory code).
- [ ] `config('billing.enforced')` default false: confirm `.env.example` documents the rollout
      order (founding tenant needs a subscription before flipping it on in production).

### Stripe client & secrets
- [ ] No SDK; secret only via `Http::withToken` from `services.stripe.secret`; assert no secret,
      card data, or raw response body can reach logs or exceptions (`StripeApiException` carries
      status + error code only).
- [ ] `ensureCustomer` idempotency: row lock + deterministic `Idempotency-Key` — double-click
      and lost-race both converge on one customer.
- [ ] Nested form encoding (`line_items[0][price]`) actually renders Stripe-style bodies via
      `asForm` + `http_build_query` (StripeWebhookHttpTest fakes assert the call shape — spot-check).

### Tests & docs
- [ ] `SeatConcurrencyTest` tearDown truncation: confirm it cannot destroy data a parallel
      developer DB would care about (it targets the qds_test database only) and that class
      ordering (runs before the RefreshDatabase classes alphabetically) stays irrelevant.
- [ ] The 85 new tests assert behavior, not implementation (no tautologies); spot-check the
      webhook failure-rollback and downgrade tests.
- [ ] Docs: ADR-0021 vs implementation drift; ownership-matrix rows; glossary enum values match
      `app/Shared/Enums/*`; entity count 32 consistent.

### Deferred (do NOT flag as findings)
Per-seat (quantity) Stripe pricing and licensed-seat sync; in-app plan changes beyond
Checkout/Portal (upgrades/downgrades happen on Stripe-hosted surfaces); dunning email
sequences (Stripe's own dunning applies); proration display; per-tenant provider cost
attribution; per-tenant cadence; tenant offboarding/export; platform-operator role;
`/livewire/update` subscription gate (documented accepted gap, see checklist); enforcement
flag default-off until the founding tenant is subscribed.
