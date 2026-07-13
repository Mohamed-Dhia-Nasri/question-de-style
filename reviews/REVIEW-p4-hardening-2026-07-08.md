# REVIEW TODO — P4 Hardening (data quality, media lifecycle, GDPR)

Date: 2026-07-08. Scope: the uncommitted diff implementing the three remaining P4 roadmap items (`docs/80-delivery/00-roadmap.md` §P4): (1) data-quality monitoring for TikTok fragility, (2) media storage lifecycle for archived story media, (3) GDPR tooling (data-subject export, erasure, retention enforcement) satisfying DP-005.

Verification state at handoff: full suite **686 passing** (`XDEBUG_MODE=off php artisan test`), PHPStan clean except 12 pre-existing `DemoDataSeeder` errors, Pint clean. 15 new tests across `tests/Feature/Ingestion/{DataQualityTest,StoryMediaRetentionTest}.php` and `tests/Feature/Crm/GdprTest.php`.

> **STANDING TODO (deferred by operator, 2026-07-08): run the adversarial multi-agent review workflow over this full diff.** Not started — no cached run. Launch with lenses mirroring the checklist below (data-quality detector correctness, media-lifecycle safety, GDPR erasure completeness + append-only gate soundness, GDPR export completeness, governance/docs), each finding adversarially verified before reporting. This is IN ADDITION to the two older standing reviews (Module 3 whole-diff, ADR-0017 cost-optimization diff) which remain pending.

## What was built (file map)

**1. Data-quality monitoring** — detects when the DATA looks wrong even though provider calls succeed:
- `app/Platform/Ingestion/Jobs/RefreshDataQualityJob.php` — follower zero-drop / implausible-drop detection over the last two account snapshots; snapshot time-series gap detection; stale enrichment-run reaper (RUNNING → FAILED past `qds.enrichment.run_stale_after_minutes`, roadmap §"Stale enrichment-run recovery").
- `app/Platform/Ingestion/Console/CheckDataQualityCommand.php` (`qds:check-data-quality`, hourly), gated on `qds.ingestion.data_quality.enabled`.
- New `AlertType::MetricAnomaly` / `AlertType::SnapshotGap` cases; alerts raise/resolve per platform via the existing `AlertService` fingerprint dedup and surface on the existing operations dashboard.
- Config: `qds.ingestion.data_quality.{enabled, zero_drop_min_followers, drop_alert_ratio, snapshot_gap_hours}`, `qds.enrichment.run_stale_after_minutes`.

**2. Media storage lifecycle** (DP-005 retention):
- Migration `2026_07_08_100000_add_media_pruned_at_to_stories.php`; `Story.media_pruned_at`.
- `app/Platform/Ingestion/Console/PruneStoryMediaCommand.php` (`qds:prune-story-media`, daily): deletes archived story media older than `qds.ingestion.media_retention_days` (default 180, 0 disables) from the media disk; story row/metrics kept; `media_pruned_at` stamped.
- `StoryPersister` re-archival guard now also checks `media_pruned_at` so pruned media is never re-queued.

**3. GDPR tooling** (DP-005):
- Migration `2026_07_08_100001_allow_gated_gdpr_erasure.php` — extends BOTH append-only trigger functions (`qds_metric_snapshots_append_only`, `qds_analytics_append_only`) with a DELETE-only, transaction-local `qds.gdpr_erasure` gate (same mechanism as the fact-restamp gates).
- `app/Modules/CRM/Services/Gdpr/CreatorDataExporter.php` + `qds:gdpr-export-creator {id}` — full data-subject dossier as JSON on the private exports disk.
- `app/Modules/CRM/Services/Gdpr/CreatorEraser.php` + `qds:gdpr-erase-creator {id} [--force]` — single-transaction purge of ALL creator data: CRM PII, monitoring history (content, stories + media files, comments, mentions, enrichment artifacts, review actions, metric snapshots), analytics facts/dims (gated), documents + files; matview rollups refreshed after commit; identifier-only audit event.
- `app/Modules/CRM/Console/GdprEnforceRetentionCommand.php` (`qds:gdpr-enforce-retention`, daily): communication logs past `qds.gdpr.communication_log_retention_days` (default 0 = disabled, flagged not-canonically-decided) + leftover `gdpr/` export files past the export TTL.

## Review checklist

### Data-quality monitoring
- [ ] `followerAmount()` fallback (single unlabeled metric = follower count "by construction") — verify against what `DatabaseSnapshotScheduler` actually writes and against legacy snapshot rows; can a content-level metric list of length 1 ever reach the account branch?
- [ ] Zero-drop vs drop-ratio precedence and the `previous < min_followers` guard: a 90-follower account dropping to 0 stays silent by design — acceptable?
- [ ] Per-account N+1 (two-snapshot query per account, hourly): fine at 300 accounts, check the growth path / consider a window-function query.
- [ ] Snapshot-gap detector fires for accounts deliberately removed from the roster (snapshots stop by design) — false-positive rate; should it scope to CURRENT roster accounts like `DatabaseSnapshotScheduler::rosterAccounts()`?
- [ ] Alert `source` column now carries platform enum values (`TIKTOK`) where other alert types carry `SRC-*` ids — confirm no dashboard/consumer assumes the SRC- vocabulary.
- [ ] Stale-run reaper: 180-min default vs real enrichment run times; interaction with a run that is legitimately still executing on a slow queue (reaped row vs job still writing stages afterwards — job may later update the reaped row back?).
- [ ] Reaper lives inside the ingestion-side job but touches enrichment-owned telemetry — module-boundary doctrine check.

### Media storage lifecycle
- [ ] Prune cutoff keys on `captured_at`, not `expires_at` — confirm intended semantics ("age of archive", not platform expiry).
- [ ] `chunkById` + per-row `update()` under human-override protection: `media_url` could be listed in `human_overrides` — should pruning respect overrides or is lifecycle exempt?
- [ ] File-then-row ordering: disk delete before row update — a crash between leaves `media_url` pointing at a deleted file (signed-URL 404 path acceptable?); consider row-first vs file-first trade-off.
- [ ] `StoryMediaController` behavior for a pruned story (media_url null) — clean 404, no 500.
- [ ] S3 disk: per-file `delete()` loop cost at scale; batched `Storage::delete(array)` alternative.

### GDPR erasure — completeness
- [ ] Sweep for PII the eraser does NOT touch: `quarantined_records` / `provider_response_samples` (redacted + short retention — confirm that's a sufficient answer), `notifications` (task-reminder payloads may embed creator display names), `audit_logs` context payloads (kept by design — confirm the legitimate-interest position and that no context stores raw PII), `export_jobs.filters` (may pin a creatorId), queued job payloads, failed_jobs table.
- [ ] `ingestion_cycles.creator_id` nullOnDelete leaves scoped cycle rows — any other column there carrying handles?
- [ ] Analytics: `fact_mention` rows with NULL creator_id but a mention_id belonging to the erased creator's subjects are caught via `monitored_subject_id` — verify all five fact tables' identifying-column coverage (e.g. `fact_content_metric.platform_account_id` not used as a delete key; rows with NULL creator_id + account id of the erased creator).
- [ ] Aggregated rollups (brand/campaign level) still contain the creator's CONTRIBUTION until next refresh — only the two creator-keyed matviews are refreshed inline. Confirm scheduled `qds:refresh-rollups` (30 min) closes the window acceptably, or refresh all.
- [ ] `dim_geo`/`dim_creator` deleted, but loaders may re-insert from stale OLTP state if any creator rows survived — verify loader sources are all purged.
- [ ] Review actions: morph-type matching via `getMorphClass()` — confirm no morph map aliasing breaks the three type strings.

### GDPR erasure — mechanism
- [ ] The `qds.gdpr_erasure` GUC gate: `set_config(..., true)` is transaction-local — verify it cannot leak via connection pooling outside a transaction (e.g. if `erase()` is ever called inside an outer transaction that later continues), and that the savepoint behavior under nested `DB::transaction` keeps the gate scoped.
- [ ] Gate opens DELETE on ALL fact tables (broader than the per-table restamp gates) — acceptable given it's only ever set by CreatorEraser? Consider requiring the creator id in the setting value.
- [ ] Down-migration restores the exact prior trigger bodies — diff against `allow_gated_fact_shipment_restamp` to confirm no drift.
- [ ] Files deleted only after commit — but a crash between commit and file deletion orphans blobs with no retry path; consider recording paths for the retention command to sweep.
- [ ] `roster->withdraw()` inside the purge transaction uses Eloquent — confirm no model events with side effects (e.g. re-enrollment hooks) fire.
- [ ] Erasure under concurrent ingestion: a poll job mid-flight can re-insert content/snapshot rows for the deleted account (FK now gone → QueryException in the job — acceptable?) or re-create the creator's rows between id-collection and delete within the transaction (REPEATABLE READ vs READ COMMITTED phantom risk).

### GDPR export
- [ ] Dossier completeness vs the PII map: proposals (`CreatorProposalIntake`), organic mentions text, sentiment/recognition artifacts, EMV — deliberately excluded as derived/non-personal? Document the position.
- [ ] Export file lifecycle: written to `gdpr/` on the exports disk with NO ExportJob row — confirm the retention command's TTL sweep is the only cleanup and the path never leaks via any listing UI.
- [ ] `qds:gdpr-export-creator` has no permission gate beyond shell access — confirm console-only is the accepted authorization boundary (same as other qds:* commands).

### Retention enforcement
- [ ] Communication-log retention default 0 (disabled) — flagged not-canonically-decided; needs an ADR to set the real period (same class as the cadence flags).
- [ ] Story media retention default 180d — same: operational default awaiting ADR; cross-check with DP-005 doc wording and any platform-ToS constraints on retaining story media at all.

### Governance / docs
- [ ] Roadmap P4 exit criteria: media lifecycle + GDPR tooling now exist — draft the doc amendment marking P4 items delivered (with the two cost-governance items already under ADR-0017).
- [ ] DP-005 section: add pointers to the concrete tooling (commands, configs) as the "how a feature satisfies it" evidence.
- [ ] New schema deviations to log: `stories.media_pruned_at`, the widened append-only gate, `qds.gdpr.*` / `data_quality.*` config clusters, new AlertType cases (operational vocabulary).
- [ ] ADR candidates: retention periods (media, communication logs), GDPR erasure gate mechanism.


---

## Verified adversarial review — RUN 2026-07-13

Multi-agent workflow: 7 finder lenses over this diff, every candidate finding attacked by 3 independent skeptics (refute / reproduce / impact), majority-confirm. **14 confirmed** (1 HIGH, 6 MEDIUM, 3 LOW, 2 INFO + 2 folded). No cross-tenant data-exposure and no append-only-corruption vulnerability found — the erasure gate and tenant scoping hold on their intended paths. The weight is GDPR erasure/export **completeness**, not crashes.

**Verdict:** solid diff; fixable issues cluster in GDPR completeness. The clear, low-risk items are FIXED with regression tests; the ones needing a design decision or a schema change are DEFERRED with a recommendation.

### FIXED (this session, with tests; full suite 874 green)
- **[HIGH] Erasure left a previously-generated export dossier on disk (~48h).** `CreatorEraser::erase()` now deletes `tenants/{id}/gdpr/creator-{id}-*.json` (and legacy `gdpr/`) synchronously. Test: `GdprTest::test_erasure_deletes_a_previously_generated_export_dossier`.
- **[MED] Only 2 of the creator-keyed matviews were refreshed on erasure.** Now refreshes the whole `NeonAnalyticsService::ROLLUPS` set (covers `rollup_seeding_by_shipment`, `rollup_metric_by_geo`, …) so no erased-creator data lingers regardless of the config-gated scheduled refresh.
- **[MED] Data-quality detectors were not roster-scoped** → a de-rostered account's stale snapshot raised a `SnapshotGap` that could never auto-resolve, permanently masking real gaps. Both detectors now `whereIn` the active-roster account set (mirrors `DatabaseSnapshotScheduler`). Test: `DataQualityTest::test_a_derostered_account_never_raises_a_snapshot_gap`.
- **[MED] Reaper/pipeline had no compare-and-swap** → a completing run could un-fail a reaped row. `EnrichmentPipeline` terminal write is now `WHERE status = RUNNING` + `refresh()`; a reaped verdict sticks.
- **[LOW] gdpr_erasure GUC leak in a nested transaction** → explicit `set_config(..., 'off', true)` before commit; gate never outlives the purge even under a SAVEPOINT.
- **[LOW] `issue()` minted a signed URL without the disk-exists check** `stream()` does → now `abort_unless(exists)` for a clean 404 in the prune crash-window.
- **[LOW] GDPR export reported SUCCESS on a `json_encode` failure** (0-byte dossier + falsified audit) → `JSON_THROW_ON_ERROR`, returns FAILURE without writing or auditing.

### DEFERRED (recorded; each needs a decision or larger change — not a quick fix)
- **[MED] Export omits derived PII the eraser deletes** (sentiment/recognition/EMV/hashtags; snapshot count vs series). Reconcile export⇄eraser OR record the legal basis for each exclusion and drop the "every category" claim. Partly a DPO determination.
- **[MED] Audit-log context retains `display_name`/`handle` after erasure.** Real fix is stop-writing-PII-at-source (CreatorsIndex / PlatformAccountsPanel), which conflicts with the append-only audit contract — needs a redact-on-erase vs store-identifiers-only decision.
- **[MED] Erasure captures ids up-front without taking the account offline** → a concurrent analytics loader can re-introduce creator-keyed facts (no FKs) after the fact-delete, or an ingestion insert can abort the purge. Needs deactivate-first + serialize (advisory lock / final `creator_id` fact sweep).
- **[LOW] Post-commit blob/matview work lost on crash** orphans media/documents with no retry path. Needs a durable pending-deletions table + retriable job.
- **[LOW→partial] Dossier TTL granularity:** daily sweep vs 24h TTL means ~48h for non-erased subjects. The acute erasure case is fixed above; fold the `tenants/*/gdpr` sweep into the hourly `qds:prune-expired-exports` to close the rest.
- **[INFO] Communication-log retention is a global cross-tenant hard-DELETE** off one env var (latent: default 0). Make per-tenant under `TenantContext`, soft-delete, audited, `--dry-run`.
- **[INFO] Media prune deletes one S3 object per row** → batch `$disk->delete($paths)` per chunk + bulk row update.

### Invariants CLEARED
Signed+auth+policy+exists story-media serving; per-tenant data-quality alert isolation (runAs, scoped queries, per-tenant fingerprint, one tenant's failure caught); correct FK-order deletes via query builder to bypass model guards; blobs collected-before / deleted-after-commit (no rollback orphans); the append-only gate correct for the top-level `erase()` path; metric-anomaly guards; export-file prune sweeps both legacy and per-tenant dirs; reaper correctly targets only over-age RUNNING rows.

### Coverage gaps (not settleable by static review)
Reaper/pipeline race only bites if a run survives >180min (needs live worker profiling); matview exposure window depends on deployed `qds.analytics.rollup_refresh_enabled`; S3 batching impact needs a live object store; `json_encode` failure needs live rejectable data; the cross-tenant comms delete only goes live if `QDS_GDPR_COMMS_RETENTION_DAYS > 0`; full export⇄eraser PII reconciliation is partly a legal/DPO call.
