# Review handoff — CRM Stage B (relationship visibility & navigation)

Branch `feat/crm-ux-stage-a`, Stage B commits `68a9f9b..29e6b70` (11, all this feature's; Stage A sits below at `7a75393..6d9396c`). Full suite **1004 passed / 0 failed** at `29e6b70`. Plan: `docs/superpowers/plans/2026-07-17-crm-stage-b-navigation.md`. Audit source: `docs/superpowers/specs/2026-07-16-crm-ux-redesign-audit-and-plan.md`.

Already done: per-task spec+quality reviews (all Approved; several verified independently by re-running suites), fable whole-branch review (READY WITH MINOR FOLLOW-UPS, 0 blocking → product-link fix landed in `29e6b70`), live browser verification (hierarchy, brand detail, tabs + hash deep-links, participation panel, cross-links, sidebar).

## What shipped

`x-crm.context-header` chain (Client › Brand › Campaign › Run) on campaign/seeding/brand detail; `crm.brands.show` + BrandDetail Livewire page (Products/Campaigns/Seeding-runs tabs); Clients page → expandable Clients & Brands hierarchy; sidebar CRM children (Discovery + Reports removed from nav, routes kept); campaign + seeding detail as Alpine hash-tabs with Overview setup guides (Results demoted); creator ParticipationPanel + display-first identity (`resetToCurrent()` → `cancelEdit()`); CRM↔Monitoring cross-links (permission-gated both ways); results rows linked (products ?q=, creators, runs).

## For a fresh adversarial pass (checkboxes)

- [ ] BrandDetail / ParticipationPanel tenant + cross-record leaks: hostile probing beyond the existing leak tests (e.g. creator attached in tenant A viewed from tenant B via Livewire snapshot tampering).
- [ ] Display-first identity: can a crm.view-only user reach the edit form or mutate via direct Livewire calls (`edit()`, `save()` both authorize update — falsify)?
- [ ] Hash-tab deep links: XSS/HTML-injection via location.hash (guard array should make arbitrary hashes inert — verify no reflected sink).
- [ ] Sidebar children: permission edge cases (a role with crm.view but not monitoring.view etc.); ClientViewer now has ZERO nav entries — product decision needed (Stage C or ADR-0016 successor).
- [ ] Setup guides: counts loaded in route closures — stale-count windows after Livewire panel mutations (attach creator → Overview card count updates only on reload; acceptable? decide).
- [ ] Deferred perf: ParticipationPanel loads all shipments (caps display at 10); Clients & Brands search matches client names only (no brand-name search); BrandDetail lists unpaginated.
- [ ] Tab accessibility: role="tab" without aria-controls/tabpanel/arrow-keys (all three tabbed pages — one shared fix).
- [ ] Test-differentiation minors: SeedingResultsPanel link assertion can't tell per-creator vs per-shipment tables apart; dashboard slice-table link unasserted.

## Merge coordination (important)

The parallel feature `feat/monitoring-settings` (built in an isolated worktree, ready to merge into this branch) ALSO edits `resources/views/livewire/monitoring/creator-detail.blade.php` (different hunk — trend grid vs our header cross-link) and the sidebar (`$menuItems`). Expect two small conflicts when it merges; both are mechanical. Their session's `MentionClassifier.php` working-tree edit was never ours and remains uncommitted.
