# REVIEW — Per-tenant monitoring settings, engagement trend, per-pull enrichment (ADR-0023..0026)

**Status:** PENDING_REVIEW · **Date:** 2026-07-17 · **Branch:** feat/monitoring-settings (forked from feat/crm-ux-stage-a @ 2e39385)
**Spec:** docs/superpowers/specs/2026-07-17-monitoring-settings-and-decisions-design.md
**Plan:** docs/superpowers/plans/2026-07-17-monitoring-settings-and-decisions.md

## Scope (14 commits, 2e39385..d4e579b)

- `48edb82` monitoring_settings per-tenant table, model, policy, permission (ADR-0025)
- `c410715` + `538f803` context-safe MonitoringSettingsResolver (config fallbacks; trend clamp 7–90)
- `3380e89` + `40dedc6` Settings → Monitoring page (four values, admin-only save; resolver hydration)
- `9cc366b` per-tenant story-media + comms-log pruning (ADR-0025)
- `aecabc4` + `e0fd121` per-tenant shipment attribution window in MentionClassifier (+ resolver reuse)
- `cf104e5` canonical engagement-trend formula (ADR-0024); posting frequency stays undecided
- `c90d1bb` engagement-trend tile on creator detail
- `ea8df43` persister reports created content ids (ADR-0023)
- `e440df1` per-pull enrichment dispatch — content on create, stories after archive; sweep = backstop
- `4c84050` + `d4e579b` ADR-0023..0026 + doc reconciliation; stale sweep-comment retirement

**Gates at handoff:** full suite 987 tests / 4209 assertions green (isolated DB); Pint clean on all touched files; PHPStan level 6 clean on all touched files.
**Pre-existing base-branch debt (NOT this branch's changes, left untouched):** Pint style nits in `tests/Feature/Reach/ReachConfigurationServiceTest.php`; 14 PHPStan errors in `database/seeders/DemoDataSeeder.php` (13) and `database/factories/ReachConfigurationFactory.php` (1).

## Review checklist (for a SEPARATE review model — no self-review)

- [ ] Cross-tenant: monitoring_settings reads/writes cannot leak between tenants (resolver context mode, explicit …For() mode, page save under HTTP context, prune loops' explicit predicates).
- [ ] The resolver's no-context path NEVER queries another tenant's row (contrast MonitoringPlanSetting::current()).
- [ ] Per-pull dispatch fires ONLY for created rows; metric refreshes / RefreshCampaignContentJob updates cannot re-enrich (append-only EmvResult/ReachResult, recognition re-billing).
- [ ] Kill switch: every new dispatch site is gated on qds.enrichment.enabled.
- [ ] Story enrichment ordering: dispatched only after successful archive; failed/expired archives dispatch nothing; sweep still covers media-less stories.
- [ ] Trend math: window boundaries (2N fetch, N split), exclusion of no-metric items, single-component counting, zero/empty previous window → unavailable; DERIVED badge on the tile.
- [ ] Retention semantics: 0 = keep forever preserved end-to-end (page toggle → row → resolver → commands); DB CHECK ranges match page validation.
- [ ] Permission surface: monitoring-settings.manage is ADMIN-only; page view stays settings.view; non-admin save is Forbidden.
- [ ] ADR texts match the shipped behaviour; reconciled comments/docs contain no leftover "NOT canonically decided" wording for the four decided items.
- [ ] Test quality: new tests assert behaviour (not implementation), and the full suite is green.

## Known Minor findings from per-task reviews (triage list)

1. CHECK-constraint test covers only the shipment lower bound (plan-mandated).
2. communication_retention_days unsignedInteger vs story unsignedSmallInteger (cosmetic).
3. shipmentWindowDays() has no 365 ceiling on the config fallback (config default 60 mid-domain).
4. Resolver uses blanket withoutGlobalScopes() vs withoutGlobalScope(TenantScope::class).
5. friendlyError() upper-bound + comms-invalid branches untested.
6. No test asserts canManage=false UI rendering.
7. Settings sidebar entries share one SVG icon.
8. mount() mixes DI param + app(TenantContext) styles.
9. All-tenants-skipped prune night logs "0 stories" with no explicit disabled signal.
10. Per-tenant prune loop = 1 lookup + 1 delete per tenant (fine at current scale).
11. PerTenantShipmentWindowTest asserts NotSame(Seeded) — would also pass on null (non-vacuous today).
12. Theoretical resolver staleness if a future caller spans a same-tenant settings change.
13. No test at the literal trend window boundary (day = N / 2N).
14. No multi-account trend aggregation test.
15. No rounding-tie test for (int) round.
16. Dead published_at===null guard in engagementTrend loop.
17. postingFrequency method docblock phrasing drift.
18. Negative trend percent renders ASCII hyphen.
19. PersistenceResult class docblock not updated for createdIds.
20. Re-persist test doesn't explicitly assert created===0.
21. No test for published_at IS NULL non-dispatch.
22. Reworded console sweep comment drops REQ-M1-* refs.
23. decision-log front-matter last_reviewed still 2026-07-12.
