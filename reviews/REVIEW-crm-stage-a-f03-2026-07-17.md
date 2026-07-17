# Review handoff — CRM Stage A (plain language) + F03 roster repair

Branch `feat/crm-ux-stage-a`, our commits `7a75393..c531ae0` (13; a parallel monitoring feature shares the branch — zero file overlap, verified). Full suite 977 passed / 0 failed at `c531ae0`. Plan: `docs/superpowers/plans/2026-07-17-crm-stage-a-f03.md`. Audit source: `docs/superpowers/specs/2026-07-16-crm-ux-redesign-audit-and-plan.md`.

Already done: per-task spec+quality reviews (2 fix rounds), whole-branch review (READY WITH MINOR FOLLOW-UPS → the three should-fix items landed in `c531ae0`), live browser verification (hub, seeding runs, F03 flows, validation copy).

## For a fresh adversarial pass (checkboxes)

- [ ] F03 self-heal (`ShipmentsPanel::save()`): validate-then-mutate ordering + `DB::transaction`; try to construct any path that still attaches a roster row for a shipment that is never written, or writes a shipment for an off-roster creator (incl. restriction added between validate and commit).
- [ ] Backfill migration `2026_07_17_100001`: idempotency + tenant stamping against hand-crafted cross-tenant fixtures (composite FKs should make cross-tenant rows impossible — falsify).
- [ ] Detach guard: TOCTOU window between shipment count and detach; is restrict-level protection needed?
- [ ] `TenantCurrency` (container-scoped + tenant-less guard): probe queue-job and console contexts; confirm no cross-tenant memoization.
- [ ] Lint test blind spots: breadcrumb `:breadcrumbs="[...]"` arrays; PHP-side flash/reason strings; crude tag-strip vs `->` in bound attributes. Consider a follow-up pattern pass.
- [ ] EMV disclosure now hides formula/rate-card versions and links to the ACTIVE config while citing the PRODUCING one (deep-review M4 nuance, plan-directed). Decide whether a "rates as of" view is needed before client-facing exports.
- [ ] Copy drift: Geography panel headings say "Geography" while messages say "location"; platform-account remove modal lost the monitoring-history warning clause; RelationshipStatus::None selectable beside "— none —" (Stage D owns).
- [ ] `x-form.error for="detach"` non-property error-bag key (works; convention deviation).
- [ ] Deferred duplication: guard→attach→audit sequence in ShipmentsPanel vs SeedingCreatorsPanel; `mapWithKeys` descriptions snippet ×7 (extract with Stage B).

## Context worth knowing

- Dev DB was accidentally wiped during Task 6 (a `migrate:fresh` intended for a testing env that doesn't exist) and restored via `DemoDataSeeder` + `qds:refresh-rollups` — demo data is fresh/random, staff logins unchanged.
- The demo seeder now attaches shipment recipients to the run roster (F03), so newly seeded data can't reproduce the original disconnect; the migration exists for pre-fix databases.
