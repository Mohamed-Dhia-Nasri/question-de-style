# Visual Product Matching (Sub-project C) — Design

> **What this is.** The approved design for sub-project C of the seeded-product detection
> modernization: giving the detector "eyes" for the seeded **product** (not just the brand) by
> matching sub-project B's keyframes against a per-tenant catalog of product reference photos using
> Google multimodal embeddings stored in pgvector. Read
> `docs/50-modules/seeded-product-detection.md` (current behaviour) and
> `docs/50-modules/seeded-product-detection-roadmap.md` (programme + locked decisions) first.
> Sub-project A (free signals) and B (media resolution + keyframes) are merged to `main`
> (merges `55e96db`, `9c9ef89`).

---

## 1. Goal and non-goals

**Goal.** A confident visual match between a post's keyframes and a tenant's seeded-product
reference photos becomes a product-level `RecognitionDetection` (new type `VISUAL_PRODUCT`,
carrying `product_id`) that flows through the **existing** evidence → classifier path unchanged.
"Product shown but never named" finally reaches `SEEDED` when a matching shipment exists; a visual
match with no shipment stays `LIKELY_ORGANIC`. This is **closed-set retrieval** against the
tenant's plausible catalog — not open-ended object recognition (that is sub-project D's VLM).

**Non-goals (owned elsewhere).**
- The Gemini verifier for ambiguous/high-value cases = sub-project D. C emits candidates and an
  escalation flag; C never calls Gemini.
- Multi-signal fusion and confidence calibration = sub-project E. C contributes its own signal and
  explicitly-placeholder thresholds; it does not build the fusion engine. **C's confidence is
  visual-only by design**: similarity, runner-up margin, and cross-frame support — never caption,
  speech, shipment timing, or Gemini agreement.
- Full-catalog matching, open-set recognition, cropped-region embeddings (v2, §17).
- **Frame extraction stays B's.** C consumes the stored `KeyframeSet` through
  `KeyframeRepository` only — it never touches the `keyframes` table, ffmpeg, or media
  acquisition. Adaptive extraction, scene-change sampling, and denser re-extraction are B-contract
  follow-ups (§16), never C-side shortcuts. (B's sampling is already duration-adaptive — 3 frames
  for a short Reel up to 12 for a long video — so C's coverage scales with duration for free;
  coverage beyond 12 frames for long videos is B's `max_frames` knob.)

**Locked decisions inherited (not re-litigated).** Augment, don't replace; Google multimodal
embeddings + pgvector on Neon; EU data residency; tiered; per-tenant isolation (ADR-0019/0020);
fail-closed; DP-004 human precedence; per-capability kill switch default OFF (true no-op);
`qds:eval-detection` is the success metric.

---

## 2. Verified facts this design stands on

From the pre-design inspection of `main` at `9c9ef89` (details matter; each shaped a decision):

1. **B's contract.** `KeyframeRepository::forOwner(ContentItem|Story): KeyframeSet` returns
   `Keyframe` rows: `timestamp_ms` (nullable — set for `video_sample`, NULL for `thumbnail` /
   `source_image`), per-row `storage_disk`/`storage_path` on the private media disk, `checksum`,
   `source_checksum`, unique `(owner_type, owner_id, ordinal)`. 3–12 evenly-spaced frames per
   video (JPEG), 1–3 for images/stories (native format: jpg/png/webp/heic). **No scene-change
   detection (DEF-009), no re-sampling** — extraction is once-only and source video bytes are
   discarded (TikTok CDN URLs expire). The `keyframes` migration comment anticipates
   "tier C FKs embeddings to keyframes.id". `keyframes` has **no `(id, tenant_id)` unique** yet.
2. **Recognition plumbing.** Upsert identity is `(content_item_id|story_id, recognition_type,
   provider_label)` via partial unique indexes; `provider_label` is immutable; human-corrected
   fields stay out of the identity (DP-004). `recognition_type` is a closed set enforced by the
   PHP enum `RecognitionType` **and** a DB CHECK (widening precedent:
   `2026_07_18_100003_add_product_to_recognition_detections.php`). Zero exhaustive `match`
   statements over the enum exist; the classifier needs **no changes** for a new type.
3. **The kill-switch coupling trap.** `AttributionService::buildEvidence` nulls `productId` /
   `product` on **every** recognition and disables `EvidenceBundle::productDoctrine` whenever
   `qds.enrichment.text_signals.enabled` (sub-project A's switch, default OFF) is off. Without the
   gate rework in §9, VISUAL_PRODUCT evidence would be inert.
4. **The classifier does no counting.** N recognitions of one product behave exactly like 1, and a
   *weak* product-level recognition still counts as product-level alignment → `SEEDED`/`MEDIUM`
   **auto-accepted and auto-linked**. This is why the REVIEW band withholds `product_id` (§8).
5. **Review queue.** Detection-level review exists (`recognition` kind) but only surfaces
   `AI_ASSESSED` + `LOW`/`UNKNOWN`; correction is brand-only today; rejection nulls the envelope
   value + appends `human-rejected` (already honoured by `buildEvidence`).
6. **"Unavailable ≠ false"** is recorded at run level (stage markers, negative-cache rows), never
   as fabricated detection rows.
7. **pgvector is absent everywhere** — no package, no migration, and the local
   `postgres:17` docker image cannot even install the extension. Tests run against real Postgres
   (`qds_test`) in that same container.
8. **Google client posture.** Raw HTTP + API key header, global endpoints, `AiPayloadGuard` on
   every outbound payload, `ProviderCallRecorder` telemetry (source ids from the closed
   `SourceRegistry`, additions need an ADR per DP-006), `ProviderCircuitBreaker` (used by
   ingestion only today), no SDK, no service-account machinery, no Vertex/Gemini client.
9. **Catalog.** `products` already has `category` (nullable, 20-value `SectorLabel` CHECK) and
   `aliases`; **no** photo support anywhere; product CRUD is a single Livewire `ProductsIndex`
   list + modal; upload precedent is `DocumentsPanel` (`WithFileUploads`, tenant-pathed private
   storage, signed TTL download route, row+blob delete in transaction).
10. **Scoping surfaces.** `ShipmentEvidenceSource` resolves creator via
    `$target->platformAccount?->creator_id`; shipped = `shipped_at NOT NULL`; the attribution
    window is `[anchor = delivered_at ?? shipped_at, anchor + tenant shipment_window_days]`
    (default 60, ADR-0025). "Active roster" = `seeding_campaign_creator` pivot rows of campaigns in
    status ACTIVE or SHIPPING (`ActiveSeedingCreatorIds::ACTIVE_STATUSES`).
11. **GDPR.** `CreatorEraser` deletes rows in one transaction (query-builder, ordered by FK
    dependencies, transaction-local `qds.gdpr_erasure` GUC opens append-only gates), blobs after
    commit. Products are correctly never touched (catalog data). Keyframe retention
    (`qds:prune-keyframes`, daily, per-tenant days) deletes **rows and files**. No tenant
    offboarding/purge path exists anywhere (accepted platform gap).
12. **No monetary cost accounting exists**; `provider_calls` counts calls (global, tenant-less);
    the plan page shows static estimates (`IngestionCostEstimator`).
13. **Eval reality.** `qds:eval-detection` is binary post-level caption scoring, non-hermetic
    (DB-backed lexicon), no product identity, no media fields, no cost output. Baseline
    0.714 recall / 0.833 precision over 10 cases.

---

## 3. Architecture overview

A new pipeline stage **`visual_match`** in `EnrichmentPipeline::run()`, placed **between
`keyframes` and `text_signals`** (frames must already exist; `attribution` follows later in the
same run so visual detections classify immediately). All C code lives in
`app/Platform/Enrichment/VisualMatch/` plus one platform-level budget namespace.

```
EnrichmentPipeline (per post, inside EnrichContentItemJob/EnrichStoryJob, queue 'enrichment')
  hashtags → transcript → recognition → keyframes → VISUAL_MATCH → text_signals → sentiment
                                                        │            → attribution → emv → reach
                                                        ▼
   gate checks (switch / provider / creator / candidates / frames / breaker / budget / read-only)
                                                        ▼
   CandidateScope ──────────── products from in-window shipments + ACTIVE/SHIPPING roster
                                                        ▼
   FramePreparation ────────── local + free: format check, quality filter, near-dup removal
                                                        ▼
   KeyframeEmbedder ────────── embed surviving frames (cached per keyframe + model_version)
                                                        ▼
   FrameProductScorer ──────── exact cosine scan in pgvector over candidate photo embeddings
                                                        ▼
   BandMapper (pure) ───────── AUTO / REVIEW / REJECT / INCONCLUSIVE per candidate
                                                        ▼
   writes: visual_match_runs + visual_match_candidates (always)
           recognition_detections VISUAL_PRODUCT (only bands AUTO/REVIEW, DP-004 upsert)
           needs_verification flag (sub-project D's pickup)
           AiBudgetGuard::record + ProviderCallRecorder telemetry
```

**Components** (each independently testable):

| Component | Responsibility |
|---|---|
| `EmbeddingProvider` (interface) | `embedImage(bytes): array`, `modelVersion()`, `isConfigured()` — the provider seam; container-bound so C is never coupled to Google (§5) |
| `VertexMultimodalEmbeddingProvider` | First implementation: HTTP client for the Vertex AI multimodal embedding model (§5) |
| `GoogleServiceAccountTokenProvider` | Signs/caches OAuth tokens from a service-account key (§5) |
| `ReferencePhotoEmbedder` + `EmbedProductPhotoJob` | Embed reference photos on upload / backfill (§6) |
| `FrameQualityFilter` | Local, free: drop undecodable / extreme-dark / overexposed / flat frames before spending (§8) |
| `FrameDeduplicator` | Local, free: perceptual-hash near-duplicate grouping; one representative embedded per group (§8) |
| `KeyframeEmbedder` | Embed surviving keyframes on first need, cache per `(keyframe, model_version)` |
| `CandidateScope` | Resolve candidate products + their priority tier for a post (§7) |
| `FrameProductScorer` | One SQL exact-scan producing per-frame best similarity per candidate |
| `BandMapper` | Pure function: scores + category thresholds + margin → band (§8) |
| `VisualProductMatcher` | The stage orchestrator (gates, budget, persistence, outcome string) |
| `VisualMatchWriter` | DP-004-aware `VISUAL_PRODUCT` upsert (§8) |
| `AiBudgetGuard` (platform) | Capability-keyed budget decisions + usage recording (§10) |
| `ProductPhotos` Livewire modal | Upload/manage reference photos from `/crm/products` (§6) |

`MentionClassifier` is untouched. `AttributionService::buildEvidence` gets the deliberate gate
rework in §9. Everything else augments.

---

## 4. Data model

All tenant-owned tables: `BelongsToTenant`, NOT NULL `tenant_id`, `(id, tenant_id)` unique where
children composite-FK them, composite `(fk, tenant_id)` FKs per the `reach_results` pattern.

### 4.1 `product_reference_photos`
| column | type | notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | FK tenants | + `(id, tenant_id)` unique |
| product_id | FK products, **cascadeOnDelete** | + composite `(product_id, tenant_id)` FK |
| storage_disk | varchar(50) | per-row, like keyframes |
| storage_path | varchar(500) | `tenants/{tenant}/product-photos/{product}/{uuid}.{ext}` on the private media disk |
| view_label | varchar(20) nullable | CHECK IN (front, back, side, packaging, in_use, other) |
| checksum | char(64) | sha256 of stored bytes |
| width / height | int nullable | best-effort `getimagesize` |
| uploaded_by | FK users nullOnDelete | |
| timestamps | | |

App rules: min 1 to be matchable, recommend 3–5 diverse views (UI copy), hard cap
`photo_cap` = 8 per product; jpg/png only in v1 (§6), 10 MB max. Blob deletion is app-managed:
collect paths in-transaction, delete rows (cascade), blobs after commit (house order).

### 4.2 `product_photo_embeddings`
`id, tenant_id (+composite FK), product_reference_photo_id FK cascadeOnDelete, model_version
varchar(64), embedding vector(1408), created_at`; unique `(product_reference_photo_id,
model_version)`. Immutable per key: photo replaced ⇒ new photo row; model upgraded ⇒ backfill new
rows, never in-place mutation. The `vector(1408)` column width is part of the DDL: a future model
with different dimensions is a schema migration (new column/table), not a config flip — the
config `dimensions` knob exists to keep request and DDL visibly in agreement.

### 4.3 `keyframe_embeddings`
`id, tenant_id, keyframe_id FK **ON DELETE CASCADE** (+composite (keyframe_id, tenant_id) FK —
requires the new `(id, tenant_id)` unique on `keyframes`), model_version varchar(64), embedding
vector(1408), created_at`; unique `(keyframe_id, model_version)`. The DB-level cascade keeps
**both** existing deleters correct with zero code changes: `CreatorEraser`'s in-transaction bulk
deletes and the daily `qds:prune-keyframes` retention prune.

### 4.4 `visual_match_runs` — one row per analysis run (append-only)
| column | type | notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | FK | + composite FKs below |
| content_item_id / story_id | nullable FKs, CHECK `num_nonnulls(...) = 1` | + composite tenant FKs |
| correlation_id | varchar(64) | ties to enrichment run / provider calls |
| model_version | varchar(64) | |
| priority | varchar(10) | CHECK IN (high, medium) — low never produces a run (§10) |
| frames_available / frames_processed | smallint | stored vs actually embedded |
| frames_skipped_format / frames_skipped_quality / frames_deduped | smallint | coverage accounting: unsupported format, failed quality filter, near-duplicates represented by another frame |
| cache_hits | smallint | embeddings served from `keyframe_embeddings` (billed calls = embedding_calls) |
| processing_ms | int | wall-clock for the stage (observability) |
| candidates_checked | smallint | |
| best_score | numeric(5,4) nullable | best similarity across all candidates |
| outcome | varchar(30) | CHECK IN (matched, review, no_match, inconclusive, skipped_budget, skipped_read_only, skipped_provider) — `no_match` vs `inconclusive` split defined in §8 |
| rejection_reason | varchar(100) nullable | e.g. `below-review-threshold`, `margin-ambiguous` |
| thresholds | jsonb | snapshot `{category_map_used, auto, review, margin}` per candidate category |
| embedding_calls | smallint | billed calls this run (cache hits excluded) |
| estimated_cost_micro_usd | int | calls × list price |
| needs_verification | boolean default false | **sub-project D's poll flag** (§11) |
| created_at | | |

Latest run per post is authoritative (index `(content_item_id, id)` / `(story_id, id)`); history
is kept for calibration (E) and debugging. Erased with the creator (§13).

### 4.5 `visual_match_candidates` — ranked candidates per run
`id, tenant_id, visual_match_run_id FK cascadeOnDelete (+composite FK), product_id FK
**nullOnDelete** (+composite FK), product_label varchar(255) (denormalized — audit survives
catalog edits), category varchar(50) nullable, rank smallint, best_similarity numeric(5,4),
margin_to_runner_up numeric(5,4) nullable, supporting_frames jsonb (list of {ordinal,
timestamp_ms, similarity, photo_id, represented_frames}), band varchar(15) CHECK IN (auto,
review, reject), rejection_reason varchar(100) nullable, created_at`
— plus **candidate-source evidence** (why was this product considered): `source varchar(20)
CHECK IN (shipment, roster), shipment_in_window boolean, seeding_campaign_id FK nullOnDelete,
shipment_anchor_at timestamp nullable, shipment_age_days smallint nullable (anchor → post
published_at)` — and **visibility evidence**: `first_support_ms / last_support_ms int nullable,
estimated_visible_ms int nullable` (§8; null when frames carry no timestamps).

### 4.6 Budget tables (platform-level, §10)
- **`ai_usage_counters`**: `id, capability varchar(40), tenant_id FK, usage_date date, units int,
  estimated_cost_micro_usd bigint, posts_processed int, posts_skipped_budget int,
  posts_skipped_no_candidates int, updated_at`; unique `(capability, tenant_id, usage_date)`;
  atomic `INSERT … ON CONFLICT … DO UPDATE` increments. Monthly = SUM over the month's rows.
  Global = SUM across tenants.
- **`tenant_ai_quotas`**: `id, tenant_id FK, capability varchar(40), daily_units int nullable,
  monthly_units int nullable, timestamps`; unique `(tenant_id, capability)`. NULL → config
  default. This is the enterprise-quota hook; billing-plan integration is a noted follow-up for
  the billing module, not built in C.

### 4.7 Plumbing migrations
1. `CREATE EXTENSION IF NOT EXISTS vector` (raw `DB::statement`, established pattern; installs
   into `public`, the hard-coded search_path).
2. `RecognitionType::VisualProduct = 'VISUAL_PRODUCT'` enum case + DROP/re-ADD of
   `recognition_detections_recognition_type_check` with the widened set.
3. `(id, tenant_id)` unique on `keyframes` (prerequisite for 4.3's composite FK).
4. `SourceRegistry::GOOGLE_VERTEX_EMBEDDINGS = 'SRC-google-vertex-embeddings'` (+ ADR, §16).
5. `docker-compose.yml` image `postgres:17` → `pgvector/pgvector:pg17` (same Postgres major,
   existing `qds-pgdata` volume compatible); README note: the extension must exist in `qds_test`
   too — the migration's `IF NOT EXISTS` handles it once the image ships pgvector.

**No new composer dependency.** Vectors are written as pgvector text literals
(`'[0.1,0.2,…]'::vector`) and queried with raw operators through the query builder; a tiny
`VectorLiteral` helper owns formatting/validation. Exact scan only — **no HNSW/IVFFlat index**
(candidate sets are ~15–50 vectors; exact is faster there, has zero approximate-recall loss, and
is fully deterministic, a stated priority). A plain btree on `(tenant_id, product_reference_photo_id)`
via the FKs suffices.

---

## 5. The embedding provider

**Abstraction first.** C depends on an **`EmbeddingProvider` interface**
(`embedImage(string $bytes): array` returning the vector, `modelVersion(): string`,
`isConfigured(): bool`), container-bound. `VertexMultimodalEmbeddingProvider` is the first and
only v1 implementation; a future provider (or the v2 crop variant) is a new binding plus a new
`model_version`, with zero changes to the matcher, embedders, or persistence. YAGNI applies: no
provider-selection config knob until a second implementation exists — the container binding is
the seam.

**Model.** Vertex AI `multimodalembedding@001`, image input, full **1408 dimensions**, cosine
similarity. `model_version` config string is stamped on every embedding row; changing it is a
re-embed backfill, never a mutation.

**Endpoint & residency.** `https://europe-west4-aiplatform.googleapis.com/v1/projects/{project}/locations/europe-west4/publishers/google/models/{model}:predict`
— EU regional endpoint (locked EU-residency decision; note: the existing Vision/Speech clients
still use global endpoints — pre-existing, out of C's scope, flagged in the ADR). `base_url`,
`project_id`, `location` env-overridable in `config/services.php` under
`services.google_vertex_embeddings`.

**Auth.** First service-account machinery in the repo: `GoogleServiceAccountTokenProvider` reads a
JSON key file path from config, signs a JWT (`RS256`, openssl — no new dependency), exchanges it
for an OAuth token at Google's token endpoint, caches until ~60 s before expiry. The client sends
`Authorization: Bearer …`; the key material never appears in URLs, logs, or exceptions (house
rule). `isConfigured()` = key file readable + project set — unconfigured ⇒ the stage skips with a
marker, exactly like `vision:not-configured`.

**Request/response.** One image per `:predict` call (base64 inline in `instances[0].image
.bytesBase64Encoded`, `parameters.dimension = 1408`), response `predictions[0].imageEmbedding`.
Every payload passes `AiPayloadGuard::assertSafe` (bytes inline — no URLs, no personal data).
Timeout `services.google_vertex_embeddings.timeout` default 30 s, connect 10 s. Errors map onto
the existing `ErrorCategory` taxonomy and throw `ProviderCallException` (429 → RateLimited with
Retry-After, 401/403 → Authentication, 5xx → UpstreamError, non-JSON → MalformedResponse).

**Telemetry & protection.** Every call wrapped in `ProviderCallRecorder::start` /
`recordOperation` / `recordFailure` under source `SRC-google-vertex-embeddings`, operation
`embedding.embed` — provider health, the operations dashboard row, FAILING alerts and the
existing `ProviderCircuitBreaker` all come free. The matcher consults
`ProviderCircuitBreaker::shouldSkip()` **before** spending (deliberate improvement over the
recognition stage's posture, justified because every call bills); open breaker ⇒
`skipped:provider-unavailable`. A transient provider error mid-run is swallowed as a
run-level marker (`skipped_provider` outcome, mirroring `speech:provider-error`) so it never
fails the enrichment run or re-bills completed stages; already-cached embeddings are kept.

**Frame formats.** v1 embeds `jpg`/`jpeg`/`png` frames. `webp`/`heic` frames (possible for
`source_image` kinds) are skipped per-frame and counted in `frames_skipped_format` — insufficient
coverage accounting, never "absent". (Transcoding is a deferred nicety.)

---

## 6. Reference photos: upload UX and lifecycle

**Surface (approved Q1).** A "Photos" action on each row of `/crm/products` opens a manage-photos
modal (`ProductPhotos` Livewire component, registered alongside `crm.products-index`):
thumbnail grid (served via a new short-TTL **signed route** mirroring the documents download
pattern), upload (`WithFileUploads`; validation `required|file|max:10240|mimes:jpg,jpeg,png`),
optional `view_label` select, delete per photo, photo-count badge on the product row, helper copy
recommending 3–5 diverse views (front/back/side/packaging/real-world). Cap 8 enforced
server-side. Mutations require the same authorization as product create/edit in `ProductsIndex`
and record audit events `product.photo_added` / `product.photo_removed` via `AuditLogger`.
`TenantContext::idOrFail()` before any write, like `DocumentsPanel`.

**Embedding.** On upload, when `visual_match.enabled` and the provider `isConfigured()`, an
`EmbedProductPhotoJob` (queue `enrichment`, `ShouldBeUnique` on `photo:{id}:{model_version}`,
tries 4, `IngestionJobBehaviour`, `TenantContext::runAs`) embeds the photo through the
`AiBudgetGuard` (capability `embedding`, priority **high** — user-triggered) and stores the
vector. Photos uploaded while the capability is off are picked up by
**`qds:embed-product-photos`** (idempotent backfill: embeds every missing
`(photo, model_version)` pair; also the model-upgrade tool).

**Lifecycle.** Photo delete = row (+ embedding rows via cascade) in-transaction, blob after
commit. Product delete already restricts while shipments exist; when it proceeds, photo rows +
embeddings cascade at the DB and the component's delete path removes blobs after commit. Photos
are tenant **catalog** data: creator-GDPR erase correctly never touches them (verified); no
retention sweep applies (they live with the product); the missing tenant-offboarding purge is an
inherited, documented platform gap. Export: derived AI data stays out of the GDPR dossier
(existing precedent).

---

## 7. Candidate scoping (approved Q3)

For a post by creator X (resolved exactly like attribution:
`$target->platformAccount?->creator_id`; unresolvable ⇒ `skipped:no-creator`):

- **Shipment candidates:** distinct products of X's shipments with `shipped_at NOT NULL` whose
  window `[anchor = delivered_at ?? shipped_at, anchor + tenant shipment_window_days]` contains
  the post's `published_at` (stories: `captured_at`) — **byte-identical semantics to what the
  classifier can align**, same per-tenant ADR-0025 setting, zero new knobs. Each carries
  `shipment_in_window = true` and its campaign's status.
- **Roster candidates:** primary products (`seeding_campaigns.product_id`) of campaigns in status
  ACTIVE or SHIPPING whose `seeding_campaign_creator` roster contains X.

Union, deduped, tenant-scoped (the stage runs under the job's `TenantContext::runAs`). Only
candidates with ≥ 1 embedded reference photo at the configured `model_version` are **matchable**;
unmatchable candidates are recorded on the run (coverage accounting) but cost nothing. Empty
candidate set ⇒ `skipped:no-candidates`, cost zero — this is the tiering that makes most posts
free. Empty `KeyframeSet` ⇒ `skipped:no-frames` (B couldn't extract; nothing for C **or D** to
see; no escalation).

**Priority tier** (feeds the budget, §10): **high** if any candidate comes from an
ACTIVE/SHIPPING campaign (roster, or a shipment whose campaign is ACTIVE/SHIPPING); **medium**
if candidates exist but only from shipments outside active campaigns; **low** ≡ empty candidate
set ⇒ already skipped (off-peak queueing for low is deferred until D's open-set verifier gives it
meaning).

---

## 8. Matching algorithm and confidence bands (approved Q4/Q5)

**Frame preparation (local, free, before any spend).** From the stored `KeyframeSet`, in order:
1. **Format check** — only `jpg`/`jpeg`/`png` proceed (§5); others → `frames_skipped_format`.
2. **Quality filter** (`FrameQualityFilter`, config-gated, conservative defaults) — drop frames
   that cannot produce a reliable match: undecodable bytes, extreme darkness or overexposure
   (mean luminance outside bounds on a downsampled grayscale copy), or near-zero luminance
   variance (flat/blank/heavily blurred proxy). Dropped → `frames_skipped_quality`. Defaults are
   deliberately loose — this filter exists to skip garbage, not to judge photography.
3. **Near-duplicate removal** (`FrameDeduplicator`) — 64-bit difference-hash on the downsampled
   grayscale copy; frames within `hamming_threshold` (default 6) of an earlier frame join its
   group; only the earliest **representative** is embedded (`frames_deduped` counts the rest).
   A representative carries `represented_frames` (its group size) and the group's timestamp span
   — dedup reduces cost, never evidence (see support counting below).

**Scoring.** Embed the surviving frames up to `frame_budget` (default 12 = everything B stores;
the knob is purely a cost guard). Frame embeddings are cached
(`keyframe_embeddings`), so retries, multi-candidate scoring, and later re-runs are free. One SQL
exact scan computes, per candidate product, per frame, the **best cosine similarity across that
product's reference photos**: `1 - (ke.embedding <=> pe.embedding)` maxed per (frame, product),
`ORDER BY` fully specified. Determinism rules: identical embeddings + config ⇒ identical
outcome; ties between products break on lower `product_id`.

**Bands** (per candidate; thresholds resolved per the product's `category`, §12):

| Band | Rule | Detection written |
|---|---|---|
| **AUTO** | ≥ 2 distinct frames with similarity ≥ `T_auto` **and** the top candidate beats the runner-up's best similarity by ≥ `margin` | `VISUAL_PRODUCT` at **HIGH** |
| **REVIEW** | exactly 1 frame ≥ `T_auto`, **or** ≥ 1 frame in `[T_review, T_auto)`, **or** AUTO support but margin ambiguous | `VISUAL_PRODUCT` at **LOW** (queues for humans) |
| **REJECT** | best similarity < `T_review` | none — scores + reason live in `visual_match_candidates` only |
| **NO_MATCH** (run-level) | no candidate reached a band and coverage was **clean**: every available frame was processed (no format/quality skips, `frames_processed > 0`) | none — run outcome `no_match`: "we looked properly and did not see it" |
| **INCONCLUSIVE** (run-level) | no candidate reached a band and coverage **may be insufficient**: any `frames_skipped_format`/`frames_skipped_quality` > 0, or zero frames survived preparation | none — run outcome `inconclusive`: "we could not look properly" (unavailable ≠ false) |

**Support counting (dedup-aware).** "Distinct frames" for the AUTO rule means distinct
**timestamps**, counting represented frames: a video-frame dedup group contributes its full
`represented_frames` count (a product continuously on screen across a near-identical 30-second
span IS repeated visibility — cheap to verify, not one isolated moment). Null-timestamp frames
(carousel/story images) count **once per distinct visual** — two identical uploaded images are
one piece of evidence, not two. **Visibility evidence** per candidate: `first_support_ms` /
`last_support_ms` from supporting timestamps, and `estimated_visible_ms` ≈ supported represented
frames × the video's sampling span (duration / frames_available); null when timestamps are null.

Single-frame posts (stories, YouTube thumbnails) cap at REVIEW by construction — the locked
"never auto-accept one isolated match" rule; sub-project D's verifier is how they earn
automation. A lone strong hit is never auto-rejected: it lands REVIEW.

**"Denser re-sample" honestly resolved.** B cannot extract more frames (once-only, source bytes
gone). v1's dense pass = matching over **all** stored frames (no subset), and the
shipment-but-no-match case sets `needs_verification` (outcome `no_match` or `inconclusive` per
the §8 split) instead of pretending. True
denser re-extraction is registered in the deferred register, blocked on B's future operator
re-extract command (with DEF-009 scene-change).

**Detection rows** (`VisualMatchWriter`, mirroring `TextSignalRecognizer::upsert`):
- identity `(target, VISUAL_PRODUCT, provider_label)` with **`provider_label =
  'visual-product:<productId>'`** — one row per product per post, stable across re-runs;
- `detected_brand` = the product's brand name (required for evidence flow — verified),
  `detected_product` = product name, `product_id` set; assessment `value` = brand name
  (consistent with the review-correction contract);
- brand/product fields seeded only on first insert; `HumanPrecedence::allowsAiUpdate` checked;
  unique-violation → reload winner → re-check (house pattern);
- `assessment.signals` (non-empty, review-UI-visible, house naming):
  `visual-product-match:<product name>`, `visual-frames-supporting:<n>/<total>`,
  `visual-frame:t=<ms>ms:sim=<0.xx>` (per supporting frame, first 5),
  `visual-threshold:<CATEGORY>:auto=<0.xx>:review=<0.xx>:margin=<0.xx>`,
  `embedding-model:<model_version>` — new prefixes, no collision with the parsed
  `shipment-record:` / `product-unconfirmed` / `human-rejected` vocabulary;
- provenance `{source: SRC-google-vertex-embeddings, fetchedAt, sourceVersion: 'visual-match-v1'}`;
- **Edge case:** if a later run (only possible after catalog/model change — matching is otherwise
  deterministic) finds REJECT where an `AI_ASSESSED` VISUAL_PRODUCT row exists, the row is
  downgraded to LOW with signal `visual-support-withdrawn` (routes to review; humans decide).
  Human-touched rows are never modified (DP-004).

**Stage outcome strings** (in `EnrichmentRun.stages['visual_match']`): `completed:matched=N,review=M,rejected=K`,
`completed:inconclusive`, `completed:no-match`, `skipped:disabled`, `skipped:not-configured`,
`skipped:no-creator`, `skipped:no-candidates`, `skipped:no-frames`, `skipped:budget-exhausted`,
`skipped:ai-read-only`, `skipped:provider-unavailable`, `skipped:provider-error`.

---

## 9. Evidence integration (the gate rework) and review flow

`AttributionService::buildEvidence` changes (the **only** touch to existing attribution code):

```php
$textEnabled   = (bool) config('qds.enrichment.text_signals.enabled');
$visualEnabled = (bool) config('qds.enrichment.visual_match.enabled');

// Rollback no-op: with the C switch off, VISUAL_PRODUCT rows are excluded from evidence entirely.
if ($type === RecognitionType::VisualProduct && ! $visualEnabled) { continue; }

// Per-row product fields:
//  - text-family rows: flow productId/product only when $textEnabled   (unchanged, A's switch)
//  - VISUAL_PRODUCT rows: flow productId/product only when the visual precision gate passes:
//      NOT (level ∈ {Low, Unknown} && verificationStatus === AiAssessed)
// EvidenceBundle::productDoctrine = $textEnabled || $visualEnabled;
```

Existing filters (human-rejected, null brand, the LOGO precision gate keyed on the A switch) are
untouched. Verified consequences, requiring **zero classifier changes**:

- **AUTO (HIGH)** → product-level alignment → `SEEDED`/`HIGH` when strong relevance + timing —
  the already-wired path; auto-link eligible.
- **REVIEW (LOW, AI-assessed)** → brand flows, `product_id` withheld → mention caps at
  `SEEDED`/`MEDIUM` + `product-unconfirmed` → **held for review, never auto-linked** (existing
  deliberate behaviour) — a lone strong hit escalates at both the detection and mention level
  instead of silently auto-linking (closes the §2.4 trap).
- **Human approves the LOW detection** (`HUMAN_REVIEWED`) → gate passes → `product_id` flows on
  the next classification of that post → product-level `SEEDED` with a human-blessed trail.
- **Switch off** → evidence byte-identical to today (tested, §15).

**Review.** Approve/reject work generically today at `/monitoring/review` (VISUAL_PRODUCT rows at
LOW appear automatically; the queue view already prints the signals trail — frames, similarities,
thresholds). **Correcting to a different product is deferred** (the correction path is
brand-only today; reject covers v1; registered in §16). Known inherited limitation, documented:
no human decision re-triggers attribution for an already-enriched post — true of every existing
detection kind; the backfill command (§14) is the manual remedy.

---

## 10. AI budget governance (platform subsystem; C builds, D reuses)

`app/Platform/AiBudget/`: **`AiBudgetGuard`** with two calls —
`allows(string $capability, int $tenantId, int $units, Priority $priority): BudgetDecision`
(pre-spend) and `record(string $capability, int $tenantId, int $units, int $costMicroUsd, …)`
(post-spend + counter columns). Capabilities are registry entries: `embedding` (C),
`vlm_verification` (reserved for D), future providers add themselves.

- **Dimensions.** Per capability: per-post units, tenant daily, tenant monthly, global daily,
  global monthly — each with config defaults; global dimensions additionally carry a **hard**
  variant. Per-tenant overrides in `tenant_ai_quotas` (NULL → config), read through a memoizing
  resolver (monitoring-settings pattern), managed in v1 via
  `qds:ai-quota {tenant} {capability} --daily= --monthly=` + dashboard display (self-serve
  purchase lands with billing later).
- **Priority semantics** (approved): **high** (active-campaign work + user-triggered photo
  embeds) ignores tenant soft caps, stops only at the global **hard** caps, read-only mode, or
  the breaker; **medium** (shipment outside an active campaign) stops at any exhausted budget;
  **low** never reaches the guard (empty candidates ⇒ already skipped).
- **Enforcement.** The matcher asks `allows()` with the projected uncached-call count before
  embedding; deny ⇒ `skipped:budget-exhausted`, run outcome `skipped_budget`,
  `posts_skipped_budget` incremented — never a failed run, never treated as absence. The two
  per-post knobs compose as `min(visual_match.frame_budget, ai_budget…per_post_units)` — the
  matcher's own frame cap and the budget guard's per-post ceiling agree by default (both 12) and
  the stricter one wins if they ever diverge.
- **Emergency read-only mode.** Cache-backed flag (`qds:ai-read-only on|off|status`, plus env
  default `QDS_AI_READ_ONLY=false`): every `allows()` denies instantly across all capabilities;
  new AI spend stops; every existing detection/mention/result stays served
  (`skipped:ai-read-only`).
- **Threshold alerts.** `record()` detects crossings of configurable thresholds (default
  50/80/95/100 %) of any daily/monthly budget (tenant and global) and raises a deduplicated
  alert (new `AlertType::AiBudgetThreshold`; fingerprint capability+tenant+period+threshold+date;
  warning < 100 %, critical at 100 %) through the existing `AlertService` → operations alert feed.
- **Cost dashboard.** New AI-spend panel on `/monitoring/operations` (staff): per capability —
  calls today/this month, estimated spend (units × list-price constants), skipped-by-budget,
  skipped-no-candidates, average cost per processed post; per-tenant breakdown (top spenders);
  plus quality/efficiency aggregates from recent `visual_match_runs`: **cache-hit rate,
  embeddings created, frame-skip breakdown (format / quality / dedup), provider failures (from
  provider health), budget denials, average candidates per run, average processing time** — the
  quick lens for both cost and quality problems.
  Reads `ai_usage_counters` + recent runs live on render. The plan page (`/monitoring/plan`) additionally gains
  a forward-looking "Visual product matching (embeddings)" estimate row in
  `IngestionCostEstimator::perService()` (list price `EMBEDDING_PER_IMAGE = 0.0001` USD,
  assumption constants documented as estimates).

---

## 11. INCONCLUSIVE and the hand-off to sub-project D

INCONCLUSIVE means "visual coverage may be insufficient", **never** "product absent"; NO_MATCH
means "coverage was clean and thresholds were not met" (§8 defines the split). Neither is ever a
fabricated detection — both are run/candidate data:

- `needs_verification = true` when no candidate banded but an in-window shipment existed —
  **regardless of the `no_match` / `inconclusive` split** (a clean miss can still be a product
  shown in a form the reference photos don't cover; that is exactly what D verifies). The outcome
  field tells D and reviewers *why* verification is wanted;
- `needs_verification = true` also on runs whose best result was REVIEW (D verifies lone hits);
- D's contract: poll latest-run-per-post where `needs_verification` and not yet consumed
  (D adds its own consumption bookkeeping; C deliberately does not pre-build it), with
  `visual_match_candidates` supplying the ranked shortlist + frame timestamps Gemini will ground
  against. C emits the flag; C never calls Gemini.

An in-window shipment with an INCONCLUSIVE visual outcome still classifies through the normal
evidence path (text signals may exist); the mention lands wherever the classifier puts it
(typically `SEEDED`/`MEDIUM` `product-unconfirmed` via brand text, or review) — visual
inconclusiveness adds no fake signal in either direction.

---

## 12. Configuration reference

```php
// config/qds.php
'enrichment' => [
    // …existing…
    'visual_match' => [
        'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_ENABLED', false), // kill switch, true no-op
        'model_version' => env('QDS_ENRICHMENT_VISUAL_MATCH_MODEL', 'multimodalembedding@001'),
        'dimensions' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_DIMENSIONS', 1408),
        'frame_budget' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_FRAME_BUDGET', 12),
        'photo_cap' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_PHOTO_CAP', 8),
        'photo_link_ttl_minutes' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_PHOTO_LINK_TTL', 10),
        'thresholds' => [
            'default' => ['auto' => 0.65, 'review' => 0.55, 'margin' => 0.05],
            // per-category overrides, keys = SectorLabel values; packaging-prone stricter:
            'BEAUTY' => ['auto' => 0.70], 'FOOD_BEVERAGE' => ['auto' => 0.70],
            // NOTE: placeholders — calibration is sub-project E's mandate (eval golden set).
        ],
        'quality_filter' => [
            'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_QUALITY_FILTER', true),
            'min_mean_luminance' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_MIN_LUMINANCE', 10),   // 0–255
            'max_mean_luminance' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_MAX_LUMINANCE', 245),
            'min_luminance_stddev' => (float) env('QDS_ENRICHMENT_VISUAL_MATCH_MIN_STDDEV', 4.0), // flat/blank proxy
        ],
        'dedup' => [
            'enabled' => (bool) env('QDS_ENRICHMENT_VISUAL_MATCH_DEDUP', true),
            'hamming_threshold' => (int) env('QDS_ENRICHMENT_VISUAL_MATCH_DEDUP_HAMMING', 6),     // of 64 dHash bits
        ],
    ],
],
'ai_budget' => [
    'read_only' => (bool) env('QDS_AI_READ_ONLY', false),
    'alert_thresholds' => [50, 80, 95, 100],
    'capabilities' => [
        'embedding' => [
            'price_micro_usd_per_unit' => (int) env('QDS_AI_EMBEDDING_PRICE_MICRO_USD', 100), // $0.0001
            'per_post_units' => (int) env('QDS_AI_EMBEDDING_PER_POST', 12),
            'tenant_daily_units' => (int) env('QDS_AI_EMBEDDING_TENANT_DAILY', 2000),
            'tenant_monthly_units' => (int) env('QDS_AI_EMBEDDING_TENANT_MONTHLY', 40000),
            'global_daily_units' => (int) env('QDS_AI_EMBEDDING_GLOBAL_DAILY', 50000),
            'global_daily_hard_units' => (int) env('QDS_AI_EMBEDDING_GLOBAL_DAILY_HARD', 100000),
            'global_monthly_units' => (int) env('QDS_AI_EMBEDDING_GLOBAL_MONTHLY', 1000000),
            'global_monthly_hard_units' => (int) env('QDS_AI_EMBEDDING_GLOBAL_MONTHLY_HARD', 2000000),
        ],
        // 'vlm_verification' => reserved for sub-project D
    ],
],

// config/services.php
'google_vertex_embeddings' => [
    'credentials_path' => env('GOOGLE_VERTEX_CREDENTIALS'),   // service-account JSON key file
    'project_id' => env('GOOGLE_VERTEX_PROJECT'),
    'location' => env('GOOGLE_VERTEX_LOCATION', 'europe-west4'),
    'base_url' => env('GOOGLE_VERTEX_BASE_URL'),              // default derived from location
    'timeout' => (int) env('GOOGLE_VERTEX_TIMEOUT_SECONDS', 30),
],
```

---

## 13. GDPR and lifecycle summary

| Data | Erased by creator-GDPR? | Retention | Mechanism |
|---|---|---|---|
| Reference photos + their embeddings | No (catalog data — correct) | live with product/photo | app-managed delete: rows in-transaction (cascade), blobs after commit |
| Keyframe embeddings | Yes, automatically | follows keyframes (180 d default prune) | **DB ON DELETE CASCADE** from `keyframes` — both the eraser's query-builder deletes and the daily prune stay correct with no code changes |
| `visual_match_runs` / `_candidates` | Yes | with content | added to `CreatorEraser`'s ordered delete list (runs before content_items; candidates cascade from runs) + erasure-test extension |
| `VISUAL_PRODUCT` detections + review actions | Yes, already | with content | existing `recognition_detections` deletes — free |
| `ai_usage_counters` / `tenant_ai_quotas` | No (operational counters, no personal data) | counters pruned with telemetry (90 d, existing command extended) | |

No append-only triggers on the new tables in v1 (⇒ no GDPR-gate wiring needed; noted so a future
hardening pass remembers the `qds.gdpr_erasure` GUC precedent). GDPR export continues to exclude
derived AI data (existing precedent, documented). Tenant offboarding remains the platform's
accepted, documented gap.

---

## 14. Rollout and backfill

- Ship dark: `QDS_ENRICHMENT_VISUAL_MATCH_ENABLED=false` ⇒ the stage records `skipped:disabled`,
  zero provider calls, evidence byte-identical to today. Enabling affects newly enriched posts.
- **`qds:visual-match-backfill {--days=30} {--tenant=} {--dry-run}`**: for posts in the window
  that already have keyframes and a completed enrichment run, re-runs the visual stage +
  attribution under `TenantContext::runAs`, through the normal budget guard (priorities computed
  normally, so a backfill cannot blow the budget). Makes the feature useful on day one.
- Reference-photo readiness: `qds:embed-product-photos` after enabling (or after a model upgrade).
- Keyframe coverage reality (inherited): `qds.enrichment.enabled` master switch defaults OFF per
  environment, and only posts enriched since B produce frames.

---

## 15. Eval extension and testing strategy

**Eval (`qds:eval-detection`).** Golden-set cases gain an optional `visual` block:

```json
"visual": {
  "candidates": [{"product": "Nexon Labs Headset", "category": "TECH",
                   "photo_vectors": [[…]], "source": "shipment", "shipment_in_window": true}],
  "frame_vectors": [{"t_ms": 1500, "vec": […]}, …],
  "expected": {"product": "Nexon Labs Headset", "band": "auto"},
  "brief_appearance": false
}
```

Scored through the **real** `BandMapper` (pure function; cosine over fixture vectors in PHP —
dimension-agnostic, so fixtures use small vectors): reports **product-level precision/recall**
(product identity compared — fixing the wrong-brand-counts-as-TP blindness for visual cases),
**false positives broken down by category**, band distribution, missed-brief-appearance count
(`brief_appearance: true` cases ending reject/no-match/inconclusive), **average similarity margin**
across cases, **unsupported/quality-skipped frame rate** (from per-case frame metadata), and
estimated embedding cost per case (billable frame count × list price). Runtime-only metrics —
provider latency, real cache-hit rate — deliberately live on the operations dashboard (§10), not
in eval, because fixtures cannot measure them honestly. The existing text metrics are untouched;
the visual section needs no DB and no network (deterministic). Growing this set is how
sub-project E later calibrates the §12 placeholders.

**Tests (TDD, PHPUnit, base `Tests\TestCase`, `RefreshDatabase` on real Postgres — now with
pgvector — factories/fixtures, `XDEBUG_MODE=off`).** Highlights per component:

- `BandMapper` pure unit tests: band boundaries, margin ambiguity, single-frame cap, category
  threshold resolution, determinism/tie-breaks, dedup-aware support counting (represented-frame
  groups vs null-timestamp images), NO_MATCH vs INCONCLUSIVE outcome split.
- `FrameQualityFilter` unit tests: undecodable bytes, dark/overexposed/flat synthetic images
  (generated with GD in-test), conservative-defaults pass-through of normal frames, config off.
- `FrameDeduplicator` unit tests: identical frames group, near-identical within hamming
  threshold, distinct frames survive, representative carries group size + span, config off.
- `VectorLiteral` + pgvector round-trip; exact-scan scorer against seeded vectors (known cosine
  geometry) asserting per-frame/per-candidate maxima and ordering.
- `VertexEmbeddingClient`: `Http::fake` (house pattern) + faked token provider; error taxonomy;
  `AiPayloadGuard` compliance; recorder wiring; breaker consultation.
- `CandidateScope`: window edges (anchor fallback, per-tenant days), roster statuses, dedupe,
  priority computation, unmatchable-candidate accounting, tenant isolation (`makeTenantPair`).
- `VisualProductMatcher` pipeline integration: container-stubbed embedder; every skip marker;
  INCONCLUSIVE + `needs_verification`; run/candidate persistence; budget deny; read-only mode.
- `VisualMatchWriter`: DP-004 matrix (AI vs human-touched rows), provider_label idempotency,
  `visual-support-withdrawn` downgrade, concurrency (unique-violation recovery).
- `buildEvidence` gate matrix: **switch off ⇒ evidence byte-identical to today**; LOW-gate
  (AI-assessed vs human-reviewed); productDoctrine OR-logic; end-to-end classifier outcomes
  (AUTO→HIGH SEEDED; REVIEW→MEDIUM product-unconfirmed, not auto-linked; approval unlock).
- `AiBudgetGuard`: dimension exhaustion per priority, quota overrides, month rollover, alert
  threshold crossings (deduped), read-only, counter atomicity.
- Livewire `ProductPhotos`: upload validation, cap, signed route TTL + cross-tenant denial,
  delete row+blob order (`Storage::fake('media')`), audit events.
- GDPR: extend `KeyframeErasureTest` — runs/candidates deleted, keyframe embeddings cascade,
  photo tables untouched; retention-prune cascade test.
- Eval command: visual fixtures scoring, cost output, text metrics unchanged.
- New `KeyframeFactory` (Keyframe currently lacks `HasFactory`) shared by C's tests.

---

## 16. Deferred, documented, and doc amendments

**New deferred-register entries:** denser re-extraction (blocked on B's future force re-extract;
links DEF-009/DEF-010); long-video coverage beyond 12 frames (B's `max_frames` knob — any
adaptive/scene-change extraction goes through B's contract, never C-side); product-level
correction in the review UI (brand-only today); human
review does not re-trigger attribution (pre-existing, backfill command is the remedy);
webp/heic frame transcoding; off-peak queue for low-priority work (awaits D); tenant offboarding
purge (pre-existing platform gap); billing-plan quota purchase (billing module follow-up);
existing Vision/Speech clients still on global endpoints (EU-residency follow-up).

**ADR-0029 "Visual product matching — sub-project C":** records the Vertex embeddings
provider addition (`SRC-google-vertex-embeddings`, DP-006 requires the ADR), first
service-account auth, pgvector adoption + docker image change, exact-scan decision, band
thresholds as E-calibrated placeholders, the `buildEvidence` gate generalization, and the AI
budget governance subsystem.

**Doc amendments on landing:** glossary `ENUM-RecognitionType` (+`VISUAL_PRODUCT`);
`seeded-product-detection.md` §3/§12 (new signal source; visual limitation removed); roadmap
status table (C ✅); `DemoDataSeeder` note (it randomizes over `RecognitionType::cases()` — after
the enum gains VISUAL_PRODUCT it will fabricate such rows in demo data; acceptable, noted).

---

## 17. v2 — designed-for, not built

- **Adaptive sampling passes.** The run/candidate tables already record frame timestamps and
  coverage; when B grows scene-change (DEF-009) and re-extraction, pass 2/3 slot into
  `VisualProductMatcher` as frame-selection strategies. Transcript cues (`{start, dur}` — the
  only timestamped non-visual signal today) can window frame selection without B changes.
- **Cropped-region embeddings.** The `EmbeddingProvider` interface + `KeyframeEmbedder` are the
  seam: a future object-localization step (new billed Vision feature — not requested today)
  yields crops embedded through the same interface under a distinct `model_version` suffix, so
  full-frame and crop embeddings coexist without schema change and the matcher never learns the
  difference.
