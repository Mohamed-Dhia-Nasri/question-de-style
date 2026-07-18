# Sub-project B — Media Resolution + Keyframe Sampling for Seeded-Product Detection

- **Date:** 2026-07-18
- **Status:** Design — awaiting user review before implementation planning
- **Author:** brainstorming session (Claude + user)
- **Part of:** the "modern (VLM + embeddings) seeded-product detection" programme (sub-projects A–E). This spec covers **B only**.
- **Sibling spec:** `docs/superpowers/specs/2026-07-18-seeded-detection-tier0-free-signals-design.md` (sub-project A). **B is independent of A** — only C and D depend on B — and can proceed in parallel. Shared source files (the platform adapters, `RecognitionService`, `RecognitionNormalizer`, `BrandLexicon`) must be coordinated with A to avoid merge conflicts (§13).

---

## 1. Context & motivation

The current recognition stack is inert or lossy on real media, which blocks the visual tiers (C embeddings, D Gemini) before they even start. A whole-pipeline audit confirmed three gaps that make "vision" unavailable on 2 of 5 platforms and silently drop long/large video:

1. **TikTok and YouTube store watch-page URLs, not media files.** `YouTubeContentAdapter` writes `https://www.youtube.com/watch?v=…` into `media_urls` ([YouTubeContentAdapter.php:141](../../../app/Platform/Ingestion/Providers/YouTube/YouTubeContentAdapter.php)); `TikTokContentAdapter` writes `webVideoUrl` — also a watch page ([TikTokContentAdapter.php:112](../../../app/Platform/Ingestion/Providers/TikTok/TikTokContentAdapter.php)). `MediaFetcher` only forwards `image/*`, `video/*`, or `application/octet-stream` bytes ([MediaFetcher.php:44-51](../../../app/Platform/Enrichment/Recognition/MediaFetcher.php)), so the `text/html` watch page is rejected and recognition is skipped as `media:fetch-failed`. **Result: no recognition on TikTok or YouTube.**

2. **The 20 MB inline cap silently drops large/long video.** `MediaFetcher::MAX_BYTES = 20_000_000` ([MediaFetcher.php:32](../../../app/Platform/Enrichment/Recognition/MediaFetcher.php)) rejects anything larger, and `GoogleVideoIntelligenceClient` sends the whole clip **inline as base64** ([GoogleVideoIntelligenceClient.php:40](../../../app/Platform/Enrichment/Http/GoogleVideoIntelligenceClient.php)). Large Instagram Reels and long videos are dropped with a generic failure marker, not an explainable one.

3. **Only a sliver of each asset is analyzed.** Today: the first video asset, the first 3 carousel images ([RecognitionService.php:50](../../../app/Platform/Enrichment/Recognition/RecognitionService.php)), the first ≤60 s of audio, and the whole clip. There is **no frame sampling** — the representative frames that C (embeddings) and D (Gemini) need do not exist.

**B delivers analyzable media for every platform:** it resolves the real media file where the pipeline currently has only a watch page, samples representative keyframes across the whole video for all platforms, moves large video off the silent-drop path with explainable markers, and persists a uniform **`KeyframeSet`** artifact that C and D plug into. B is **pure plumbing** — it makes no embedding or VLM calls.

## 2. Agreed decisions (from brainstorming)

| # | Decision | Choice |
|---|---|---|
| 1 | TikTok media resolution | Read the **real download URL the frozen Clockworks actor already returns** (`mediaUrls` / `videoMeta.downloadAddr`) into `media_urls`. **In-freeze** (same provider) — no ADR, no extra cost. |
| 1 | YouTube media resolution | No downloadable video in-freeze (Data API is ToS-bound to metadata + thumbnails). Use the **max-res thumbnail** as the single keyframe **plus the transcript text** via a **new** `pintostudio/youtube-transcript-scraper` actor. Visual product/brand recognition on YouTube is deferred to C/D. |
| 1 | YouTube transcript seam | The scraped transcript feeds the **existing `SPOKEN_BRAND` normalization path** (replaces YouTube's dead audio path). No re-implementation of matching; A's later product-aware upgrades benefit it automatically. |
| 2 | Keyframe strategy | **Even-interval sampling** (deterministic): `N = clamp(duration / interval, min, max)` frames evenly across the whole video. Scene-change is a documented future config mode. |
| 3 | Keyframe storage | **Persist** on the private, tenant-scoped media disk, with the **same retention + GDPR-erase treatment as story media** (ADR-0013/0025). Decouples C/D from ephemeral (expiring) source URLs. |
| 4 | Large video / GCS | **No GCS in v1.** Stream + size-guard downloads; keep the **inline** Video Intelligence pass for videos within the inline cap (backward compatible); for larger videos skip only the whole-video VI pass (`recognition:whole-video-skipped-too-large`) while **keyframes still succeed**. GCS-URI whole-video VI is deferred behind a future ADR. |
| 5 | C/D abstraction | A `keyframes` table of **stable, FK-able rows** with a **polymorphic owner** (`owner_type`/`owner_id`), plus a **`KeyframeSet` value object** that C and D depend on. C FKs its per-frame embedding rows to `keyframes.id`; D iterates the set. |
| — | Transcript storage (user revision) | A dedicated **`content_transcripts`** table (not a column) — refreshable, multi-language, segment-ready. |
| — | Media download (user revision) | The **single-download / shared-artifact** principle applies to **images as well as video** — one `MediaWorkspace` per target feeds both recognition and keyframe persistence. |

## 3. Goal & success criteria

**Goal:** every enriched ContentItem/Story yields (a) a persisted, tenant-scoped `KeyframeSet` of representative frames, and (b) — where the platform provides it — a stored transcript, so C and D have analyzable inputs on **all five platforms** including long/large video, without regressing existing Instagram recognition or the ~1,200 green tests.

**Success criteria:**
- A TikTok video pull produces a downloaded video, N sampled `video_sample` keyframes persisted on the private disk, and (for in-cap videos) the existing inline Video Intelligence + Speech passes still run.
- A YouTube pull produces a `thumbnail` keyframe **and** a `content_transcripts` row, and the transcript yields `SPOKEN_BRAND` detections through the existing normalizer (no Google-Speech call).
- A large Instagram Reel (over the inline cap, under the download cap) produces keyframes and a `recognition:whole-video-skipped-too-large` marker — **not** a silent drop and **not** a generic failure.
- A video over the streaming download cap produces a distinct `media:too-large` marker and no frames (fail-closed, explainable).
- Instagram post/carousel/reel recognition is unchanged; the full test suite stays green.
- Re-running enrichment is idempotent (extract-once): no duplicate keyframes, no orphaned frames.
- `KeyframeSet::forOwner()` returns a uniform contract across content, stories, and platforms — the exact insertion point C and D consume.

## 4. Scope

**In scope:** real-media resolution for TikTok (adapter field) and YouTube (thumbnail + transcript); the new `SRC-apify-youtube-transcript` provider + ADR-0028; a `MediaWorkspace` that downloads all media once with a streaming size guard and centralized cleanup; a `KeyframeSampler` (ffmpeg even-interval); persisted `keyframes` (polymorphic owner) + `KeyframeSet` VO + repository; `content_transcripts` table + transcript→`SPOKEN_BRAND` seam; the `RecognitionService` refactor to consume the workspace; explainable skip markers incl. the two-way too-large split; `qds:prune-keyframes` retention + `CreatorEraser` erase; config + kill switches.

**Out of scope (later sub-projects):** reference photos + multimodal embeddings + pgvector visual product match (C); Gemini VLM grounding + multilingual speech (D); confidence calibration (E); **GCS + whole-video Video Intelligence on huge files**; **real YouTube video-file download** (frame-level YouTube visual); scene-change sampling; edit-distance OCR/ASR error recovery. The last three are recorded in the deferred register (§12).

## 5. Design

### B1 — Resolve the real media (ingestion adapters)

**Intent:** stop storing watch pages where a media file is available in-freeze.

- **`TikTokContentAdapter`** ([TikTokContentAdapter.php:104-124](../../../app/Platform/Ingestion/Providers/TikTok/TikTokContentAdapter.php)) — set `mediaUrls` to the **real download URL** the Clockworks actor already returns, keeping `webVideoUrl` as the `permalink` (as today). The exact field (`mediaUrls[]`, `videoMeta.downloadAddr`, or `videoMeta.downloadAddrNoWaterMark`) is **confirmed against a stored `ProviderResponseSample`** during implementation and mapped through `Extract::string` (schema drift quarantines loudly, as elsewhere). Same frozen `SRC-clockworks-tiktok-scraper` (ADR-0002) → **no new provider, no ADR** (only a data-source-matrix clarification of which field we read).
- **`YouTubeContentAdapter`** ([YouTubeContentAdapter.php:134-150](../../../app/Platform/Ingestion/Providers/YouTube/YouTubeContentAdapter.php)) — set `mediaUrls` to the **max-res thumbnail** URL from `snippet.thumbnails` (prefer `maxres` → `standard` → `high`), and set `permalink` to the watch URL. `MediaFetcher` then fetches a real `image/*` for the single keyframe. No video download (ToS/freeze).

Caveats designed around: TikTok CDN download URLs are **short-lived** and may be watermarked. Enrichment runs per-pull immediately after ingestion (ADR-0023), so the URL is fresh. A later recovery-sweep run that finds it expired downloads a `403/410` → distinct `media:too-old` marker → clean skip; the next pull refreshes it (never fabricated).

### B2 — YouTube transcript (new provider)

**Intent:** give YouTube a spoken-signal path since its audio is not downloadable in-freeze.

- **New provider `SRC-apify-youtube-transcript`** — the `pintostudio/youtube-transcript-scraper` actor, run through the existing `ApifyClient` boundary (SSRF-safe, telemetered, circuit-broken, cost-metered). Added to `SourceRegistry`, the data-source matrix, and **ADR-0028** (§11). Actor id is env-tunable (`services.apify.actors.youtube_transcript`), kill-switched (`qds.ingestion.youtube_transcript.enabled`), and only invoked for YouTube content items.
- **`YouTubeTranscriptClient`** (new, `app/Platform/Ingestion/Http/` sibling of `YouTubeClient`) + wiring into YouTube content ingestion: after the video-details call, one batched actor run fetches transcripts for the fetched video ids; each transcript is persisted to a `content_transcripts` row (§6). Absent captions → no row (graceful).
- **Transcript → `SPOKEN_BRAND` seam.** `RecognitionNormalizer` gains a `transcriptBatch(string $text, Provenance $provenance)` that turns a raw transcript into `SPOKEN_BRAND` `RecognitionCandidate`s using the **same** `BrandLexicon` path Speech-to-Text output flows through today. In `RecognitionService`, a YouTube ContentItem with a stored transcript produces `SPOKEN_BRAND` detections from the transcript **instead of** the (impossible) audio-extraction path — provenance stamped `SRC-apify-youtube-transcript`, not `SRC-google-speech-to-text`. **Shared with A:** `RecognitionNormalizer`/`BrandLexicon` are A's product-aware upgrade surface; coordinate so A's `matchAllInText` + product resolution automatically enrich YouTube transcripts.

### B3 — Single-download media workspace + keyframe sampling

**Intent:** download each target's media **once**, feed every consumer from the same local bytes, and sample representative frames.

- **`MediaWorkspace`** (new, `app/Platform/Enrichment/Media/`) — the per-target owner of all local media temp files, built once per enrichment run and torn down in a `finally` (centralized cleanup; scraped bytes are untrusted). Interface:
  - Built from a **ContentItem** (downloads each `media_urls` entry once, streaming + size-guarded) or a **Story** (reads the already-archived file from the private disk — no download).
  - `images(): list<LocalMediaAsset>` and `video(): ?LocalMediaAsset`, where a `LocalMediaAsset` carries `{ tempPath, byteSize, contentType, sha256, sourceUrl, provenance }`.
  - Accumulates acquisition **markers** (`media:none`, `media:fetch-failed`, `media:too-large`, `media:too-old`).
  - `close()` unlinks every temp file; the workspace is the **single owner** of temp-file lifecycle for recognition and keyframes alike.
- **`MediaFetcher` upgrade** ([MediaFetcher.php](../../../app/Platform/Enrichment/Recognition/MediaFetcher.php)) — add `streamToFile(string $url, string $sinkPath, int $maxBytes): bool` that preserves the existing SSRF doctrine (resolve-once, refuse non-public, `CURLOPT_RESOLVE` pin, manual redirect re-validation) but **streams to a sink** with a hard byte cap (reject on `Content-Length` over cap; enforce mid-stream via a curl progress guard). Two thresholds:
  - `inline_max_bytes` (~20 MB, the former `MAX_BYTES`, now explicit config) — the ceiling for sending bytes **inline** to Vision/Video-Intelligence/Speech.
  - `keyframes.download_max_bytes` (larger, e.g. 200 MB) — the ceiling for **downloading at all**. Over it → `media:too-large`, no download, no frames.
  The legacy inline `fromPublicUrl` and `fromStory` remain for the inline read path; the workspace uses `streamToFile` for acquisition and reads bytes from the temp file when an inline provider needs them.
- **`KeyframeSampler`** (new, mirrors [AudioExtractor.php](../../../app/Platform/Enrichment/Recognition/AudioExtractor.php)) — given a **video file path** + duration + config, runs ffmpeg with a fixed argument vector (`-nostdin`, `-v error`, hard timeout, `-y`) to extract **`N = clamp(ceil(duration / interval_seconds), min_frames, max_frames)`** evenly-spaced JPEG frames, downscaled to `max_width` at `jpeg_quality`. Deterministic given identical input bytes. `isAvailable()` gates on the ffmpeg binary exactly like `AudioExtractor`. Any failure → null frame list + `keyframes:extraction-failed` (never fabricated).

### B4 — Persisted keyframes + the `KeyframeSet` contract (the C/D seam)

**Intent:** one uniform, FK-able, tenant-scoped artifact for C and D.

- **`keyframes` table + `Keyframe` model** (§6) — a **polymorphic owner** (`owner_type`/`owner_id`) row per frame carrying `ordinal`, `timestamp_ms` (null for thumbnail/source image), `storage_disk`, `storage_path`, `width`, `height`, `kind` (`video_sample` / `thumbnail` / `source_image`), `checksum` (sha256), `provenance`, and `BelongsToTenant`. Partial-unique `(owner_type, owner_id, ordinal)`.
- **`KeyframeWriter`** (new) — persists frames to the private disk under `tenants/{tenant_id}/keyframes/{platform}/{platform_account_id}/{external_id}/{ordinal}.jpg` (mirrors [ArchiveStoryMediaJob.php:100-107](../../../app/Platform/Ingestion/Jobs/ArchiveStoryMediaJob.php)) and inserts rows, keyed idempotently on `(owner, ordinal)`. **Extract-once:** if the owner already has keyframes and `force` is not set, sampling is skipped entirely — a re-run never re-samples, so it can never orphan an embedding C attached to a `keyframes.id`. Populates:
  - **video_sample** — the sampled ffmpeg frames (TikTok, Instagram reel/video, story video).
  - **thumbnail** — the single downloaded YouTube thumbnail.
  - **source_image** — Instagram post/carousel images and image-story frames (the image **is** the frame). **Persisted from the same `MediaWorkspace` bytes Vision recognized** — one download, one checksum, no second CDN call.
- **`KeyframeSet` value object + `KeyframeRepository::forOwner(Model): KeyframeSet`** — the C/D-facing contract: the ordered frames + source-media provenance + status (`extracted` / `skipped:<marker>`). C depends on it to attach one embedding per frame (`embedding.keyframe_id` FK); D depends on it to iterate frames into Gemini. Neither C nor D touches the table directly — the VO is the swappable seam.

### B5 — Pipeline wiring, retention & erasure

- **`EnrichmentPipeline`** ([EnrichmentPipeline.php:53-100](../../../app/Platform/Enrichment/EnrichmentPipeline.php)) — build the `MediaWorkspace` **once** per target, pass it to `RecognitionService::enrich(...)` and a new `KeyframeExtractor` stage, and `close()` it in a `finally`. New `keyframes` stage line recorded on the `EnrichmentRun`, gated by `qds.enrichment.keyframes.enabled`. The keyframe stage runs even when no Google recognition provider is configured (frames are for C/D, independent of the AI providers).
- **`RecognitionService`** ([RecognitionService.php:66-223](../../../app/Platform/Enrichment/Recognition/RecognitionService.php)) — **stops downloading**; consumes `MediaWorkspace` bytes. Behavior preserved for the in-cap path (Vision on images, inline VI + Speech on video ≤ `inline_max_bytes`). Over `inline_max_bytes` (but downloaded): emit `recognition:whole-video-skipped-too-large` for the VI pass; SPOKEN_BRAND still runs from `AudioExtractor` (fed the workspace video file). YouTube: SPOKEN_BRAND from the stored transcript.
- **`qds:prune-keyframes`** (new command, mirrors [PruneStoryMediaCommand.php](../../../app/Platform/Ingestion/Console/PruneStoryMediaCommand.php)) — iterates tenants, resolves per-tenant `keyframeRetentionDaysFor()` (`MonitoringSettingsResolver`, default `qds.enrichment.keyframes.retention_days` = 180; `0` = keep forever), deletes expired frame files **and rows**, confirm-blob-gone-before-delete (M31 pattern). Registered in `PlatformServiceProvider` + a `->daily()` schedule line, self-gated on the kill switch.
- **`CreatorEraser`** ([CreatorEraser.php](../../../app/Modules/CRM/Services/Gdpr/CreatorEraser.php)) — extend the erasure transaction to collect keyframe file paths and delete `keyframes` rows (where `owner` resolves to the creator's content or stories) + `content_transcripts` rows (by content id), then delete the frame blobs after commit (blobs go only after rows are durably gone). Frames and transcripts are creator-derived personal data (faces, spoken words), so they are purged exactly like story media.

## 6. Data-model changes (summary)

| Table | Change |
|---|---|
| **`keyframes`** (new) | `id`, `owner_type` varchar, `owner_id` bigint, `tenant_id` (BelongsToTenant), `ordinal` int, `timestamp_ms` int null, `storage_disk` varchar, `storage_path` varchar, `width` int, `height` int, `kind` varchar (`video_sample`/`thumbnail`/`source_image`), `checksum` char(64), `provenance` jsonb, timestamps. **Partial-unique** `(owner_type, owner_id, ordinal)`; index `(owner_type, owner_id)`; index `(tenant_id)`. |
| **`content_transcripts`** (new) | `id`, `content_item_id` FK, `tenant_id` (BelongsToTenant), `language` varchar null, `text` text, `segments` jsonb null (timestamped cues when the actor supplies them), `provider` varchar (SRC id), `provenance` jsonb, `checksum` char(64), `fetched_at` timestamp, timestamps. **Unique** `(content_item_id, language, provider)` (refresh updates text/segments/checksum/fetched_at). |
| `content_items` | No column change (transcripts moved to their own table per revision). TikTok/YouTube `media_urls` now carry a real media/thumbnail URL — data change only, existing schema. |
| (config) | New `qds.enrichment.keyframes.*`, `qds.enrichment.recognition.inline_max_bytes`, `services.apify.actors.youtube_transcript`, `qds.ingestion.youtube_transcript.enabled` (§8). |

New source id: `SourceRegistry::APIFY_YOUTUBE_TRANSCRIPT = 'SRC-apify-youtube-transcript'`. New enum/const: `Keyframe` `kind` values. New VOs: `KeyframeSet`, `LocalMediaAsset`. New services: `MediaWorkspace`, `KeyframeSampler`, `KeyframeWriter`, `KeyframeExtractor`, `KeyframeRepository`, `YouTubeTranscriptClient`.

## 7. Data flow (worked examples)

```
TikTok video pull
  B1  adapter stores real download URL in media_urls (permalink = webVideoUrl)
  B3  MediaWorkspace streams the video once to a temp file (size-guarded)
      KeyframeSampler → N even-interval JPEG frames
  B4  KeyframeWriter persists N video_sample keyframes (idempotent on ordinal)
      RecognitionService: if temp file ≤ inline_max_bytes → inline VI + Speech (as today);
                          else → recognition:whole-video-skipped-too-large (frames still saved)
  →   KeyframeSet.forOwner(contentItem) ready for C/D

YouTube pull
  B1  adapter stores max-res thumbnail in media_urls (permalink = watch URL)
  B2  YouTubeTranscriptClient → content_transcripts row (language, text, segments)
  B3  MediaWorkspace downloads the thumbnail image (no ffmpeg — no video)
  B4  KeyframeWriter persists ONE thumbnail keyframe
      RecognitionService: transcript → RecognitionNormalizer::transcriptBatch → SPOKEN_BRAND
                          detections (provenance = SRC-apify-youtube-transcript)
  →   KeyframeSet = [thumbnail]; transcript feeds A's product-aware matching later

Instagram carousel pull
  B3  MediaWorkspace downloads each image once (size-guarded)
  B4  KeyframeWriter persists them as source_image keyframes (same bytes Vision recognized)
      RecognitionService: Vision on the same workspace bytes (unchanged behavior)

Instagram large Reel (over inline cap, under download cap)
  B3/B4  video downloaded, N video_sample keyframes persisted
      RecognitionService: recognition:whole-video-skipped-too-large (VI skipped; Speech still runs)

Video over the download cap
  B3  MediaWorkspace refuses the download → media:too-large; no frames (fail-closed, explainable)

Story (video)
  B3  MediaWorkspace reads the already-archived local file (no download)
  B4  KeyframeSampler + KeyframeWriter → video_sample keyframes owned by the Story
```

## 8. Configuration additions

```php
// config/qds.php → enrichment block
'recognition' => [
    'inline_max_bytes' => (int) env('QDS_ENRICHMENT_INLINE_MAX_BYTES', 20_000_000),
],
'keyframes' => [
    'enabled'            => (bool) env('QDS_ENRICHMENT_KEYFRAMES_ENABLED', true), // kill switch
    'interval_seconds'   => (int) env('QDS_ENRICHMENT_KEYFRAME_INTERVAL_SECONDS', 6),
    'min_frames'         => (int) env('QDS_ENRICHMENT_KEYFRAME_MIN', 3),
    'max_frames'         => (int) env('QDS_ENRICHMENT_KEYFRAME_MAX', 12),
    'max_width'          => (int) env('QDS_ENRICHMENT_KEYFRAME_MAX_WIDTH', 1280),
    'jpeg_quality'       => (int) env('QDS_ENRICHMENT_KEYFRAME_JPEG_QUALITY', 3), // ffmpeg -q:v
    'download_max_bytes' => (int) env('QDS_ENRICHMENT_KEYFRAME_DOWNLOAD_MAX_BYTES', 200_000_000),
    'retention_days'     => (int) env('QDS_ENRICHMENT_KEYFRAME_RETENTION_DAYS', 180), // per-tenant override via MonitoringSettings
    'ffmpeg_path'        => env('QDS_ENRICHMENT_FFMPEG_PATH', 'ffmpeg'), // reuse the audio binary path
],

// config/qds.php → ingestion block
'youtube_transcript' => [
    'enabled' => (bool) env('QDS_INGESTION_YOUTUBE_TRANSCRIPT_ENABLED', true), // kill switch
],

// config/services.php → apify.actors
'youtube_transcript' => env('APIFY_ACTOR_YOUTUBE_TRANSCRIPT', 'pintostudio~youtube-transcript-scraper'),
```

Keyframes reuse the existing private disk `qds.ingestion.media_disk` and the story-media signed-URL/retention machinery.

## 9. Invariants & error handling (fail-closed, never fabricated)

- **Explainable markers, never a generic drop:** `media:none`, `media:fetch-failed`, `media:too-old` (expired source URL), `media:too-large` (over the download cap — nothing produced), `recognition:whole-video-skipped-too-large` (over the inline cap — **VI skipped, keyframes succeed**), `keyframes:ffmpeg-unavailable`, `keyframes:extraction-failed`, `keyframes:disabled`, `youtube-transcript:unavailable`, `youtube-transcript:disabled`. Every skip is recorded on the `EnrichmentRun`.
- **Idempotent / extract-once:** keyframes keyed on `(owner, ordinal)`, transcripts on `(content_item_id, language, provider)`; a re-run is a no-op unless forced — never duplicates frames, never orphans a C embedding.
- **Deterministic:** even-interval sampling with fixed frame count for a given duration; fixed ffmpeg argument vector; no `Date.now()`-style nondeterminism in frame selection.
- **Tenant-scoped:** `keyframes` and `content_transcripts` carry `BelongsToTenant`; frame paths are per-tenant; the prune command uses explicit `tenant_id` predicates (scheduler is tenant-less).
- **DP-005 doctrine preserved:** bytes are downloaded and (when needed) sent **inline**; no media URL leaves the platform; no GCS; `AiPayloadGuard` unchanged.
- **DP-004 human precedence** is untouched — B produces media artifacts, not human-correctable classifications; the transcript→`SPOKEN_BRAND` detections flow through the **existing** upsert + precedence path unchanged.
- **Untrusted-input hardening:** scraped bytes drive ffmpeg via a fixed arg vector with `-nostdin` + timeout (the `AudioExtractor` pattern); the streaming download keeps `MediaFetcher`'s SSRF pin + redirect re-validation.
- **Kill switches:** `keyframes.enabled` and `youtube_transcript.enabled` gate the new stages; off = no behavioral change.

## 10. Backward compatibility

- Instagram post/carousel/reel recognition is **behaviorally unchanged** for in-cap media (same providers, same inline sends, same detections). The `MediaWorkspace` refactor is behavior-preserving; characterization tests lock the existing `RecognitionService` outputs before the refactor.
- The former `MAX_BYTES = 20 MB` becomes `recognition.inline_max_bytes` with the same default — no change for existing small media.
- New tables, markers, and stages are **additive**; the new stages are kill-switched. The ~1,200-test suite stays green.

## 11. ADR-0028 — Seeded-detection media resolution & keyframe sampling

One cohesive ADR records the B decisions:

1. **TikTok media-field resolution** — reads an existing field of the frozen Clockworks actor; **no** provider change (data-source-matrix clarification only).
2. **Amends ADR-0001 / DP-006** to add exactly one provider, `SRC-apify-youtube-transcript` (YouTube captions/transcript **text only**, EU, cost-metered, kill-switched). Nothing else in the frozen set changes.
3. **Keyframes as persisted derived personal data** — extends ADR-0013 (object storage) and ADR-0025 (per-tenant retention) to a new derived-media class with story-media-equivalent lifecycle + GDPR erase.
4. **Deliberate non-adoption of GCS** — whole-video Video Intelligence on files over the inline cap is **deferred**; v1 covers them with keyframes and an explainable marker. Reversing DP-005's "URL never leaves the platform" doctrine is out of scope for B.

Deferred-register entries (`docs/20-cross-cutting/01-deferred-register.md`): "real YouTube video-file download (frame-level YouTube visual)"; "GCS-URI whole-video Video Intelligence"; "scene-change keyframe sampling".

## 12. Testing strategy

- **`KeyframeSampler`** — real-ffmpeg-or-skip integration tests (mirror `AudioExtractorTest`: render synthetic `testsrc` clips, assert frame count = `clamp(duration/interval, …)`, dimensions ≤ `max_width`, determinism across two runs). Missing/undecodable/muted media → null + marker.
- **`MediaWorkspace` / `MediaFetcher.streamToFile`** — `Http::fake` on **literal-IP** hosts (SSRF guard passes without DNS), assert single download per asset, size-guard rejects over-cap (`Content-Length` + mid-stream), redirects re-validated, temp files cleaned on `close()` and on exception.
- **Pipeline tests** — container-stub `KeyframeSampler` (the `RecognitionPipelineTest` pattern of binding an anonymous subclass) so pipeline tests don't shell out; assert the `keyframes` stage records on the `EnrichmentRun`, is idempotent, and coexists with recognition sharing one workspace.
- **Adapters** — TikTok download-field + YouTube thumbnail mapping from stored `ProviderResponseSample` fixtures under `tests/Fixtures/providers/`; a new `youtube-transcript.json` fixture for the transcript actor; absent fields → graceful empty.
- **Transcript seam** — `RecognitionNormalizer::transcriptBatch` produces `SPOKEN_BRAND` candidates; provenance = the transcript provider; a YouTube item with a transcript yields detections with no Google-Speech call.
- **Markers** — large-video (over inline, under download) → `recognition:whole-video-skipped-too-large` + frames present; over-download → `media:too-large` + no frames; expired URL (403/410) → `media:too-old`.
- **Retention + erase** — `qds:prune-keyframes` deletes expired frames + rows per tenant with confirm-blob-gone-before-stamp; `CreatorEraser` removes keyframes + transcripts + files; `RefreshDatabase` + auto-tenant throughout.
- Run with `XDEBUG_MODE=off vendor/bin/phpunit` (repo convention).

## 13. How B sets up C, D, and coordination with A

- **The `KeyframeSet` VO + `keyframes.id`** are the exact insertion points C (one embedding per frame, `embedding.keyframe_id` FK) and D (frames → Gemini) plug into — no further media plumbing needed downstream.
- **`content_transcripts`** is D's multilingual-speech starting point (segments already modeled) and, immediately, A's text-mining input for YouTube.
- **Measurement:** once C/D land on top of B, uplift is measured against A's `qds:eval-detection` scorecard.
- **Shared files with A (coordinate to avoid merge conflicts):** `TikTokContentAdapter`, `YouTubeContentAdapter` (A adds mentions/product tags; B changes `media_urls`), `RecognitionService`, `RecognitionNormalizer`, `BrandLexicon` (A adds product-aware matching; B adds `transcriptBatch` + workspace consumption). B is otherwise independent and can merge before or after A.

## 14. Open questions / risks

1. **TikTok download field** — exact field (`mediaUrls` vs `videoMeta.downloadAddr` vs no-watermark variant) and watermark/expiry behavior confirmed against a real `ProviderResponseSample` during implementation. If the actor stops returning a usable URL, TikTok degrades to `media:fetch-failed` (graceful).
2. **Transcript actor schema** — the `pintostudio` actor's output shape (language field, segment timestamps, availability for videos without captions) confirmed against a live/sampled payload; missing captions → no row. Cost per YouTube item is a new per-pull charge — gated by its kill switch and the enrichment content window.
3. **Thumbnail representativeness** — a YouTube `maxres` thumbnail is often a designed cover, not in-video content; it is a weak single frame until (deferred) real YouTube video download. The eval harness will quantify YouTube recall.
4. **Download cap tuning** — `download_max_bytes` (200 MB default) trades disk/CPU cost against coverage of very long videos; operational config.
5. **ffmpeg availability** — keyframes require the same binary as `AudioExtractor`; when absent, `keyframes:ffmpeg-unavailable` (graceful) — confirm the production image ships ffmpeg.
6. **Temp-dir sizing** — the `MediaWorkspace` may hold a large video + N frames at once; ensure the worker temp volume is sized for `download_max_bytes` + frames, and that `close()` cleanup is exception-safe.
7. **Story keyframe volume** — sampling every story video adds frames + retention load; story media already has a per-tenant keep-time, and keyframes inherit the same window (bounded).
```

