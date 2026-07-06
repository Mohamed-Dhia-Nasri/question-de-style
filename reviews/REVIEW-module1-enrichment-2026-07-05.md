<!--
  Deep-Review Handoff
  Written by the IMPLEMENTATION model. Consumed by a SEPARATE review model.
-->

# Deep Review — Module 1 Step 3: AI Enrichment (SVC-EnrichmentAI)

- **review_status:** PENDING_REVIEW
- **outcome:** —
- **implemented_by:** claude-fable-5
- **implementation_date:** 2026-07-05
- **reviewer:** unassigned
- **deep_review_trigger:** major multi-file feature step; security/personal-data surface (AI payload policy); new authN/Z surfaces (review queue, EMV management); new migrations

---

## 1. Implementation summary

Implements SVC-EnrichmentAI (P1): organic-seeding attribution over an evidence chain (recognition + hashtag + shipment-link + timing; shipment alone and hashtag alone never prove SEEDED), Unicode-safe hashtag extraction/matching against configured campaign/brand/product/agency lists, Google Vision / Video Intelligence recognition orchestration with brand-lexicon normalization, a sentiment boundary (captions/transcripts only — comments never analyzed, DEF-005), a reusable DP-004 human review workflow (queue = envelope query; append-only correction history; human precedence over later AI runs), canonical DERIVED metrics with unavailable-never-zero semantics, a reach-estimator boundary (unavailable — no canonical method), a fully configurable/versioned EMV system (canonical Σ metric×rate structure, validated rate cards, single-active lifecycle, append-only reproducible results), and External API Monitoring reuse for the Google providers (ProviderCall telemetry, health states, deduplicated alerts incl. new RATE_LIMIT_RISK). **Out of scope:** Module 3 seeding CRM (shipments consumed via `SeedingEvidenceSource` contract; Null default), dashboards/exports, analytics facts/rollups, comment collection (DEF-005), open-web listening (DEF-006), audio derivation for SPOKEN_BRAND.

## 2. Changed files

**Enrichment platform (new)** — `app/Platform/Enrichment/`:
- `EnrichmentPipeline.php`, `DefaultEnrichmentService.php` — stage orchestration (hashtags → recognition → sentiment → attribution → EMV) with EnrichmentRun telemetry; replaces `PendingEnrichmentService` binding.
- `Attribution/` — `MentionClassifier` (pure evidence-chain rules), `AttributionService` (evidence assembly + human-precedence upsert), `EvidenceBundle`, `ShipmentEvidence`, `ClassificationResult`, `NullSeedingEvidenceSource`.
- `Hashtags/` — `HashtagNormalizer` (NFKC + casefold), `HashtagExtractor`, `HashtagMatcher` (generic blocklist, ambiguity), `HashtagEnricher` (idempotent upsert preserving human resolutions), DTOs.
- `Recognition/` — `RecognitionService` (orchestrator + telemetry), `RecognitionNormalizer` (validated candidates; text stored only on brand match), `BrandLexicon`, `MediaFetcher` (inline bytes only — no URLs to providers), `RecognitionCandidate`.
- `Http/` — `GoogleApiClient` (key in header, AiPayloadGuard on every payload, classified sanitized errors), `GoogleVisionClient`, `GoogleSpeechClient`, `GoogleVideoIntelligenceClient`.
- `Sentiment/` — `SentimentEnricher` (captions/transcripts only), `UnavailableSentimentClassifier` (no canonical model — unavailable), `SentimentPrediction`.
- `Review/` — `ReviewQueue` (canonical envelope query + ambiguous hashtags), `ReviewService` (approve/correct/reject/unresolved; Gate-authorized; append-only `review_actions`; audit-logged).
- `Metrics/DerivedMetricsService.php` — canonical MET-* formulas; posting frequency / engagement trend return unavailable (no canonical formula).
- `Reach/UnavailableReachEstimator.php`, `Contracts/ReachEstimator.php` — estimator boundary; views never become reach.
- `Emv/` — `EmvConfigurationValidator` (controlled expression model = canonical `rate_card_sum` only), `EmvConfigurationService` (lifecycle + audit), `EmvCalculator` (unavailable without active config; append-only disclosed results).
- `Support/` — `ConfidenceScore` (config cut-points), `AiPayloadGuard` (DP-005 outbound guard), `HumanPrecedence`, internal enums (`HashtagScope`, `ReviewDecision`, `EmvConfigurationStatus`, `EnrichmentRunStatus`).
- `Jobs/EnrichContentItemJob.php`, `Jobs/EnrichStoryJob.php`, `Console/RunEnrichmentCommand.php`, `Models/EnrichmentRun.php`.

**Monitoring module** — `app/Modules/Monitoring/`:
- Models: `HashtagList`, `ContentHashtag`, `ReviewAction` (append-only), `EmvConfiguration`, `EmvResult` (append-only); `@property` docblocks added to `Mention`/`RecognitionDetection`/`SentimentAnalysis`; `ContentItem`/`Story` gained enrichment relations.
- Policies: `ContentHashtagPolicy`, `HashtagListPolicy`, `ReviewActionPolicy`, `EmvConfigurationPolicy`, `EmvResultPolicy` (registered in `MonitoringServiceProvider`).
- Livewire: `Review/ReviewQueueIndex`, `Emv/EmvConfigurationsIndex` + views under `resources/views/livewire/monitoring/` + `monitoring/review.blade.php`, `monitoring/emv.blade.php`; `/monitoring/review` and `/monitoring/emv` routes; monitoring index links.

**Migrations:** `2026_07_05_100001_create_enrichment_tables.php` (hashtag_lists, content_hashtags, review_actions, enrichment_runs), `2026_07_05_100002_create_emv_tables.php` (emv_configurations with one-ACTIVE partial unique index, emv_results).

**Shared/config:** `PermissionsCatalog` (+`emv.manage`, ADMIN); `AlertType` (+`RATE_LIMIT_RISK`); `NormalizedBatch` items docblock widened; `config/qds.php` `enrichment` section; `config/services.php` google_vision/google_speech/google_video_intelligence; `routes/console.php` sweep schedule; `.env.example`; `PlatformServiceProvider` bindings (EnrichmentService live; SentimentClassifier/ReachEstimator/SeedingEvidenceSource → unavailable/null defaults); `Brand` model `@property` docblock.

**Factories:** `HashtagListFactory`, `ContentHashtagFactory`, `EmvConfigurationFactory`.

**Tests (132 new):** `tests/Feature/Enrichment/{HashtagPipeline,Attribution,RecognitionPipeline,Sentiment,ReviewWorkflow,MetricsAndReach,Emv,EnrichmentPipeline}Test.php`, `tests/Unit/Enrichment/{HashtagExtractor,MentionClassifier,EmvConfigurationValidator,AiPayloadGuard}Test.php`.

## 3. Canonical documents relied upon

- `docs/00-meta/03-glossary.md` — every enum used verbatim (`ENUM-MentionType` incl. no-CONFIRMED_ORGANIC rule, `ENUM-RecognitionType`, `ENUM-SentimentLabel`, `ENUM-ConfidenceLevel`, `ENUM-VerificationStatus`, `ENUM-MetricTier`); `GL-EMV`, `GL-EstimatedReach`, `GL-Mention`.
- `docs/30-data-model/00-data-model.md` — entity shapes (`ENT-Mention`, `ENT-RecognitionDetection`, `ENT-SentimentAnalysis`), envelopes, metrics catalog (`MET-EngagementRate` `(likes+comments+shares+saves)/engagementBase`, `MET-ViewRate`, `MET-CommentRate`, `MET-AveragePerformance/Median`, `MET-EMV` `Σ(metric_i × rate_i)` ESTIMATED, `MET-EstimatedReach`).
- `docs/20-cross-cutting/00-data-principles.md` — DP-001 (tiering), DP-002 (provenance), DP-003 (confidence), DP-004 (review loop, human precedence), DP-005 (personal data), DP-006 (frozen providers).
- `docs/20-cross-cutting/01-deferred-register.md` — DEF-003 (CONFIRMED reach unavailable), DEF-005 (comments never analyzed), DEF-006 (open-web hashtag subjects not processed); unavailable-never-empty rule.
- `docs/50-modules/module-1-monitoring.md` — REQ-M1-002/005/006/008/009/011, AC-M1-002/003/006/007/009/010/011/020, §2.1 tiering table, §4 sources.
- `docs/40-integrations/00-data-source-matrix.md` — SRC-google-* → RecognitionType mapping; Video Intelligence OPTIONAL; provider stack frozen.
- `docs/05-decisions/decision-log.md` — ADR-0001/0002 (providers), ADR-0008 (envelope doctrine), ADR-0011 (roster-first), ADR-0012 (stack).
- `docs/60-architecture/00-system-architecture.md` — SVC-EnrichmentAI L3 boundary; envelope enforcement at the boundary.

## 4. Known deviations / open conflicts

1. **Five new undocumented tables** (`hashtag_lists`, `content_hashtags`, `review_actions`, `enrichment_runs`, `emv_configurations`+`emv_results`) — no canonical ENT-* defines hashtag storage, correction history, enrichment telemetry, or the EMV config/result schema. Flagged in every migration/model; needs data-model amendments (same class as the ingestion ops tables).
2. **`emv.manage` permission added** to `PermissionsCatalog` (ADMIN only) — permission names are not canonically enumerated; traceable to REQ-M1-011 "configurable".
3. **Confidence cut-points** (`qds.enrichment.confidence` 0.85/0.60) and **shipment attribution window** (60 days) are config defaults, NOT canonical decisions — flagged, need an ADR.
4. **Generic-hashtag blocklist** default list in `config/qds.php` is operational config, not canon.
5. **Engagement-rate components**: platforms that don't report a component (e.g. no saves on YouTube) contribute nothing — the sum runs over observed components only (missing ≠ 0). Disclosed in the service docblock; canonical formula names all four components without addressing missing ones.
6. **Follower growth** implemented as last−first over the ordered snapshot series (AC-M1-008 "reconstructed from ordered snapshots") — the doc names no single-number formula.
7. **Sentiment model undecided** — no NLP provider exists in the frozen registry and sentiment is canonical-internal; bound to an Unavailable classifier until an ADR picks the model. No sentiment rows are produced in production config.
8. **Reach estimation method undecided** — estimator boundary returns unavailable; no formula invented.
9. **SPOKEN_BRAND requires audio derivation** (extract audio track from video) — no canonical pipeline exists; recognition reports the skip. Speech client is implemented and tested at the HTTP/normalizer level.
10. **`rates.countries` rejected by the EMV validator** — content carries no geo attribution in v1, so market variations cannot be applied honestly.
11. **Module 3 dependency**: SEEDED automated attribution requires shipment records; until P3 lands `SeedingEvidenceSource` (Null default = no records), SEEDED arises only from manual confirmation via review corrections (reason = proving record, AC-M1-003 signal recorded).
12. **`AlertType::RateLimitRisk`** added to the internal (non-canonical) alert vocabulary.
13. **`Mention`/`RecognitionDetection`/`SentimentAnalysis`/`Brand` gained `@property` docblocks** (type-safety only, no behavior change).

## 5. Tests & checks

- **Executed (passing):** `XDEBUG_MODE=off vendor/bin/phpunit` → **OK (328 tests, 1195 assertions)** (188 pre-existing + 140 new, incl. the 8-test `EnrichmentHardeningTest` covering the §9 fixes); `vendor/bin/phpstan analyse` (level 6) → no errors; `vendor/bin/pint` clean; `php artisan migrate` all three enrichment migrations (`…100001`, `…100002`, `…100003_harden_enrichment_tables`) applied on PostgreSQL.
- **Executed (failing / skipped):** none. (One real bug found and fixed during test authoring: `EmvCalculator` mass-assigned `created_at` → MassAssignmentException under strict mode.)
- **Not executed:** live Google Vision / Speech-to-Text / Video Intelligence calls (no credentials; all provider interaction is Http::fake with synthetic payloads per DP-005); queue workers against Redis/Horizon; browser-level Livewire interaction (component logic covered indirectly via services; no Livewire feature tests written for the two new pages).
- **External integrations not verified:** Google API request/response shapes are implemented from the public v1 REST contracts but NEVER verified against live endpoints; Video Intelligence long-running-operation polling (`operations/{name}:wait`) is the least-verified path. `XDEBUG_MODE=off` note: local Xdebug 3.3.2 segfaults on faked connection exceptions — known env issue, documented in the previous review file.

---

## 6. Review checklist

> Reviewer: mark `[x]` only after you have **verified** the item. Add a note per item.

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

1. **Evidence-chain doctrine** — *Focus:* `MentionClassifier`. *Do:* construct bundles for every combination of {shipment, hashtag, recognition, label, timing}. *Expected:* SEEDED only with aligned link+relevance; no PAID without label; UNKNOWN/LOW on ambiguity. *Risk if wrong:* organic asserted as fact / false seeding attribution — the core product claim.
2. **Human precedence end-to-end** — *Focus:* `AttributionService`, `SentimentEnricher`, `RecognitionService::persist`, `HashtagEnricher`. *Do:* correct each output type, re-run the full pipeline twice. *Expected:* zero human decisions overwritten. *Risk:* silent loss of analyst work (DP-004 violation).
3. **AI payload security** — *Focus:* `GoogleApiClient::post` + `MediaFetcher`. *Do:* attempt payloads with PII/signed URLs; inspect a real request built from a story on the private disk. *Expected:* guard throws; only inline bytes leave. *Risk:* GDPR/DP-005 breach.
4. **EMV reproducibility under concurrency** — *Focus:* `EmvConfigurationService::activate` + partial unique index. *Do:* activate two configs concurrently (two processes). *Expected:* exactly one ACTIVE, no constraint explosion visible to users, old results untouched. *Risk:* two rate cards silently mixing into reports.
5. **Upsert races** — *Focus:* `AttributionService`/`RecognitionService` `firstOrNew` writes (no DB unique index behind mention (subject,content) or detection (target,type,brand)). *Do:* run two enrichment jobs for the same content concurrently. *Expected/Risk:* duplicate mention/detection rows are possible — assess severity and whether a unique index amendment is needed.
6. **Google response drift** — *Focus:* `RecognitionNormalizer` + `GoogleVideoIntelligenceClient::annotateVideo` polling. *Do:* replay realistic (not happy-path) Vision/VI payloads. *Expected:* quarantine, never crash/silent store. *Risk:* schema drift crashing enrichment.

## 8. High-risk areas

- `app/Platform/Enrichment/Attribution/MentionClassifier.php` — densest rule logic; a subtle alignment/timing bug misclassifies attribution at scale.
- `app/Platform/Enrichment/Http/GoogleVideoIntelligenceClient.php` — sleep-based operation polling inside a queue job; never live-verified; worst-case runtime near job timeout.
- `app/Platform/Enrichment/Review/ReviewService.php` — envelope transitions encode DP-004; a wrong verificationStatus path would let AI overwrite humans or strand queue items.
- `database/migrations/2026_07_05_100001_create_enrichment_tables.php` — COALESCE-based unique index on hashtag_lists and restrictive FK defaults; deletion behavior of referenced campaigns/brands/lists is untested.

---

## 9. Reviewer findings

> An adversarial multi-lens review (4 finder lenses × 3-skeptic majority-vote verification) ran on 2026-07-05 and confirmed 8 findings (4 others were refuted). All 8 were fixed by the implementer in the same session; a `2026_07_05_100003_harden_enrichment_tables` migration + a dedicated `EnrichmentHardeningTest` (8 regression tests) back them. A fresh reviewer should still independently verify each fix.

- [x] **HIGH** `AttributionService::hashtagEvidence` — a human-**rejected** ambiguous hashtag (`resolved_at` set, `resolved_hashtag_list_id` null) was re-promoted as full targeted evidence, inverting the reviewer's "none apply" decision (DP-004). *Fix:* skip rows where `resolved_at !== null && resolved_hashtag_list_id === null`. *Regression:* `test_rejected_ambiguous_hashtag_carries_no_attribution_evidence`.
- [x] **HIGH** `mentions` upsert had no backing unique index → concurrent enrichment passes could double-insert. *Fix:* partial unique indexes `(monitored_subject_id, content_item_id)` / `(…, story_id)` + `UniqueConstraintViolationException` catch-and-reselect in `upsertMention`. *Regression:* `test_duplicate_mention_is_rejected_by_the_unique_index`.
- [x] **HIGH** `EnrichContentItemJob` / `EnrichStoryJob` not `ShouldBeUnique` → an overlapping sweep could enqueue a second concurrent pass. *Fix:* both now `ShouldBeUnique` keyed on target id (`uniqueFor = timeout + 60`).
- [x] **MEDIUM** `RecognitionService::persist` keyed the DP-004 precedence guard on the mutable `detected_brand`; a human correction let the raw provider label be re-detected as a fresh AI row. *Fix:* new immutable `provider_label` column is the upsert identity (+ partial unique indexes). *Regression:* `test_corrected_recognition_is_not_re_detected`.
- [x] **MEDIUM** `AttributionService::enrich` never retracted a stale AI SEEDED/LIKELY_ORGANIC mention when its sole evidence was human-rejected (classifier returns null) — it stayed invisible to the review queue. *Fix:* `retractStaleMention` downgrades AI-owned mentions to UNKNOWN/LOW (re-enters the queue); human-owned mentions untouched. *Regressions:* `test_stale_ai_mention_is_retracted_when_evidence_disappears`, `test_human_corrected_mention_is_not_retracted`.
- [x] **MEDIUM** `MediaFetcher::fromPublicUrl` SSRF — untrusted scraped media URLs were fetched with only a scheme check, then forwarded to Google. *Fix:* reject hosts resolving to loopback/link-local/private/reserved ranges, re-validate every redirect hop, cap redirects, and content-type gate to image/video. *Regression:* `test_media_fetcher_refuses_internal_hosts`.
- [x] **MEDIUM** `review_actions` / `emv_results` append-only invariant was enforced only by Eloquent model events → query-builder / bulk writes bypassed it. *Fix:* Postgres `BEFORE UPDATE OR DELETE` triggers mirroring the metric_snapshots trigger. *Regressions:* `test_review_actions_are_append_only_at_the_database`, `test_emv_results_are_append_only_at_the_database`.
- [x] **HIGH** (duplicate report of finding #1 from the integrity lens) — same rejected-hashtag inversion; fixed above.

**Refuted (not defects):** AiPayloadGuard signed-URL pattern coverage; EmvConfiguration `$fillable` lifecycle-column exposure (guarded by the service, not mass-assigned from user input); standalone duplicate-RecognitionDetection concern (idempotent upsert); EnrichmentRun stuck-RUNNING reaping (1/3 votes — noted as a follow-up, not a defect: a hard-killed job leaves a RUNNING row that the sweep then skips; worth a stale-run sweeper later, but not blocking).

## 10. Review sign-off

- **Reviewer:** —
- **review_status → REVIEWED on:** —
- **outcome:** —
- **Summary:** —
