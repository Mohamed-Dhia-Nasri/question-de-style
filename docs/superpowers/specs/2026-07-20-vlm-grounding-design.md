# VLM Grounding & Multilingual Speech (Sub-project D) — Design

> **What this is.** The approved design for sub-project D of the seeded-product detection
> modernization: a Gemini vision-language-model (VLM) **verifier/grounder** for the hard cases the
> cheaper tiers could not settle, and the **multilingual speech** upgrade that removes the
> de-DE-only / ~60-second limits. Read `docs/50-modules/seeded-product-detection.md` (current
> behaviour) and `docs/50-modules/seeded-product-detection-roadmap.md` (programme + locked
> decisions) first. Sub-projects A (free signals), B (media resolution + keyframes), and C (visual
> embedding matching) are merged (A `55e96db`, B `9c9ef89`, C `0936c1b` + fixes `7381477`/`973804c`).
> Brainstormed and approved 2026-07-20 (six questions, each decision recorded below).

---

## 1. Goal and non-goals

**Goal.** For posts C escalates (`visual_match_runs.needs_verification`), a Gemini VLM ingests the
stored keyframes + caption + transcript + the tenant's **candidate product list** and returns a
**structured, catalog-grounded verdict**. A confirmed product becomes a product-level
`RecognitionDetection` (new type `VLM_PRODUCT`, carrying `product_id`) that flows through the
**existing** evidence → classifier path unchanged (product-level → HIGH SEEDED is already wired; a
VLM "yes" reaches SEEDED only when an in-window shipment exists — the classifier enforces that,
the VLM never auto-confirms seeding on its own). In parallel, the speech path is upgraded from
v1/de-DE/≤60 s sync to **Speech-to-Text v2 + `chirp_3` on the EU multi-region** with language
auto-detect, brand/product phrase hints, and chunked long-audio coverage. This is **closed-set
grounding** — "which of THESE catalog products appears" — never open-ended object recognition.

**Non-goals (owned elsewhere).**
- Retrieval (embeddings/pgvector, candidate generation) = sub-project C. D **verifies C's
  candidates**; it never re-derives them and never calls the embedding model.
- Multi-signal fusion + confidence calibration across visual/VLM/timing/caption/hashtags/OCR/
  speech ("Gemini agreement") = sub-project E. D emits its `VLM_PRODUCT` signal and persists the
  per-candidate verdicts E will consume; it does not build the fusion/calibration engine. D's
  band thresholds are **explicitly-placeholder** values E calibrates.
- Open-set recognition, reference-photo-in-prompt verification, GCS-based batch speech — v2
  (§17) or deferred (§16).

**Locked decisions inherited (not re-litigated).** Augment, don't replace; Gemini + Google, EU
data residency; **tiered — the VLM is the expensive last resort**; per-tenant isolation
(ADR-0019/0020); fail-closed; DP-004 human precedence; own kill switch default OFF (true no-op);
`qds:eval-detection` is the success metric.

**Approved brainstorm decisions (2026-07-20).**
| # | Question | Decision |
|---|---|---|
| 1 | Trigger policy + DEF-021 | **Flag-driven + frameless surfacing** (§4) |
| 1b | Cost caps | **Moderate defaults** (§11) |
| 2 | Output schema/grounding | **Enum-grounded, per-candidate verdicts** (§6) |
| 3 | Band mapping | **Confidence + visibility required** (§7) |
| 4 | Speech architecture | **Chunked inline + tiered duration** (§9) |
| 5 | Async design | **Async jobs + scheduled sweep** (§10) |
| 6 | Caption PII in payloads | **Fail-closed skip** (`skipped:payload-guard`) (§6/§12) |

---

## 2. Verified facts this design stands on

### 2a. Code as built (`main` @ `0936c1b`, inspected 2026-07-20)

1. **C's escalation contract.** `VisualProductMatcher::needsVerification()` sets
   `needs_verification = true` when any candidate banded REVIEW, or when nothing banded and
   `CandidateSet::hasInWindowShipment()` — regardless of the `no_match`/`inconclusive` split.
   Skipped runs (`skipped_budget`/`skipped_read_only`/`skipped_provider`) always record
   `needs_verification = false` (a `VisualMatchRunRecorder` guard *throws* otherwise), and
   zero-frame / no-candidate / disabled posts write **no run row at all** (DEF-021). "Latest run
   per post = max id" via indexes `(content_item_id, id)` / `(story_id, id)`.
2. **C's candidate shortlist.** `visual_match_candidates` carries per-candidate
   `product_id (nullOnDelete) + product_label (denormalized) + category + band +
   supporting_frames jsonb + source/shipment_in_window/seeding_campaign_id` — the ranked
   shortlist D grounds against, with frame timestamps.
3. **Budget guard.** `AiBudgetGuard::allows(capability, tenantId, units, Priority)` /
   `record(...)`; `Priority` = `high|medium` (High bypasses tenant soft caps, stops only at
   global **hard** caps / read-only / breaker). `'vlm_verification'` is only a **comment** in
   `config/qds.php:420` — unregistered capabilities deny `'unknown-capability'` (fail-closed)
   until D adds the block. `qds:ai-read-only` covers every capability for free.
4. **Re-classification precedent.** `qds:visual-match-backfill` re-runs
   `AttributionService::enrich($target)` after a `completed:*` match, inside the same
   `TenantContext::runAs`. `AttributionService::enrich` has exactly two callers today
   (pipeline + that command); D's jobs become the third, same shape.
5. **Evidence gate as built** (`AttributionService::buildEvidence`): VISUAL_PRODUCT rows are
   excluded entirely when C's switch is off; product fields flow only when
   `NOT (AI_ASSESSED && level ∈ {Low, Unknown})`; `productDoctrine = text || visual`. The same
   pattern extends to VLM rows (§7). `MentionClassifier::shipmentAligns` compares
   `(int) $recognition['productId'] === $shipment->productId` type-agnostically — **zero
   classifier changes needed** (verified at `MentionClassifier.php:250-257, 288-300`).
6. **Speech as built.** `GoogleSpeechClient::recognize` posts to
   `https://speech.googleapis.com/v1/speech:recognize` (global, API key `X-Goog-Api-Key`,
   `config: {languageCode: 'de-DE', enableAutomaticPunctuation: true}`, inline base64 FLAC, no
   model/no hints/no offsets). `AudioExtractor` produces mono 16 kHz FLAC, code-clamped to 60 s
   (`MAX_SECONDS_CEILING`), ≤ 7 MB. Speech markers: `speech:not-configured`,
   `speech:ffmpeg-unavailable`, `speech:audio-extraction-failed`, `speech:provider-error`
   (transient failure never fails the run). No breaker consult on the speech path today.
7. **Transcripts.** `content_transcripts` (content-item-only FK) unique
   `(content_item_id, language, provider)`, `status ∈ {available, unavailable}`, `segments`
   jsonb `list<{start, dur, text}>`; docblock anticipates "tier D's multilingual speech will add
   rows under other providers/languages". YouTube rows are consume-only in recognition
   (`transcriptBatch`, no provider call). Speech results today go straight to detections — **no
   transcript row is persisted for audio speech** (D changes that for content items, §9).
8. **Detection plumbing.** Upsert identity `(content_item_id|story_id, recognition_type,
   provider_label)`; provider_label immutable; `HumanPrecedence::allowsAiUpdate` guard;
   unique-violation → reload-winner. `RecognitionType` is a PHP enum + DB CHECK; the widening
   precedent is `2026_07_20_100002_add_visual_product_to_recognition_type_check.php`
   (DROP + re-ADD). Review queue = query over `AI_ASSESSED + LOW/UNKNOWN`; correction is
   brand-only (DEF-014); approve → HUMAN_REVIEWED unlocks the product gate.
9. **Token provider.** `GoogleServiceAccountTokenProvider` (C) implements the RS256 JWT-bearer
   flow with hardcoded embeddings cache key + source id; D generalizes it (§5/§9) rather than
   duplicating it.
10. **Jobs/queues.** Queue `enrichment` (database driver, plain workers, no Horizon);
    house job shape = `ShouldBeUnique` + `IngestionJobBehaviour` (backoff 60/300/900/1800,
    `handleProviderFailure` honouring Retry-After, `failed()` → `JobFailed` critical alert) +
    `TenantContext::runAs`. Scheduler lines live in `routes/console.php`.
11. **Docs/state.** Next free ADR number is **ADR-0030** (verified). `AiPayloadGuard::assertSafe`
    rejects (never redacts) payloads with email-like values / credential-like params / forbidden
    keys — D is the first feature to send caption/transcript **text** to a provider (§6).
    Ops dashboard AI-spend panel iterates `config('qds.ai_budget.capabilities')` — new
    capabilities appear automatically.

### 2b. External facts (official Google docs, doubly-verified + adversarially reconciled 2026-07-20; full citations §18)

1. **Model choice is forced by the EU mandate.** The only **GA + EU-resident + structured-output**
   vision-language models are **`gemini-3.5-flash`** (GA 2026-05-19, retirement "May 19 2027 or
   later") and **`gemini-3.1-flash-lite`** (GA 2026-05-07). `gemini-3.1-pro-preview` and
   `gemini-3-flash-preview` are global-endpoint-only (no residency, and PayGo usage tiers don't
   apply to previews). The Gemini 2.5 family retires **2026-10-16**. There is **no Pro-class
   model with EU residency** — the spec pins `gemini-3.5-flash` and must not promise
   flagship-Pro quality.
2. **EU endpoint.** `https://aiplatform.eu.rep.googleapis.com` (jurisdictional multi-region;
   ML processing stays within EU member states, UK/CH excluded; the `global` endpoint carries no
   residency guarantee). Non-global consumption carries a **+10 % premium** since 2026-07-01:
   3.5 Flash on `eu` = **$1.65/M input, $9.90/M output** (single rate for text+image+video+audio
   input; only HTTP 200 responses billed).
3. **Structured output.** `generationConfig.responseMimeType: "application/json"` +
   `generationConfig.responseSchema` — official wording: "You can **guarantee** that a model's
   generated output always adheres to a specific schema." Supported subset (verified list):
   `enum` (**string only**), `anyOf`, `format` (date/time kinds), `items`, `minimum`, `maximum`,
   `minItems`, `maxItems`, `nullable`, `properties`, `description`, `propertyOrdering`,
   `required` — unsupported fields are **silently ignored** and an overly complex schema
   returns `InvalidArgument: 400`. The schema counts toward input tokens. `responseJsonSchema`
   is absent from the serving reference and `responseFormat[]` is undocumented — build only on
   `responseSchema`, isolated behind D's client (the v1 type reference already marks today's
   mechanism deprecated, so the adapter seam matters).
4. **Image tokens.** Gemini 3.x uses `media_resolution` (Preview-marked): LOW = 280,
   MEDIUM = 560, default = 1120 tokens/image. D pins **MEDIUM** per frame (frames are 1280 px
   B-keyframes; 2–4× vision-cost cut vs default). Inline media ≤ 7 MB/file, formats
   PNG/JPEG/WebP/HEIC/HEIF — every B keyframe format. Up to 3,000 images/prompt (irrelevantly
   high for D). No documented total-request inline byte cap (§18 watch item).
5. **Thinking.** `thinking_level` default is HIGH and thinking tokens bill as output —
   D pins **LOW** (cost + latency; verification is extraction, not deep reasoning).
   `thinkingBudget`-style legacy knobs are mutually exclusive with `thinking_level` (400 if both).
6. **Safety.** For gemini-2.5-flash and later, configurable-category default is already **OFF**;
   CSAM + PII/SPII filters are non-configurable and always on. Blocks surface as
   `promptFeedback.blockReason` (prompt) or `finishReason SAFETY/PROHIBITED_CONTENT/SPII/…`
   (response) — D treats every block as **permanent** (`skipped:safety-block`, no retry).
7. **Batch is rejected for D.** The 50 %-discount batch path requires the request JSONL in Cloud
   Storage (media as `gs://` fileUri), has no SLA ("not a Covered Service", ≤ 72 h queue), and
   its global endpoint waives residency — incompatible with the inline-bytes doctrine and D's
   hours-scale freshness need. Online `generateContent` on the `eu` rep endpoint is the fit.
8. **Auth.** `generateContent` accepts API keys, but Google recommends service-account
   credentials for production; the repo's JWT-bearer flow (scope `cloud-platform`) works
   unchanged against `aiplatform.eu.rep.googleapis.com`.
9. **Speech-to-Text v2.** No v1 sunset date exists, but v2 is the documented path for new users
   and new features. Current models: `chirp_3` (GA 2025-10-13; **GA on the `eu` multi-region**),
   `chirp_2` (region pages say "Private GA" — do not build on it), `telephony`. **Language
   auto-detect:** `language_codes: ["auto"]` (chirp_3) — "most prevalent language",
   **dominant-language only, no per-segment code-switching promise**; detected language returned
   per result (`SpeechRecognitionResult.language_code`). Restricted explicit lists also allowed.
10. **Long audio (design-critical).** Sync `recognize` = **10 MB or 1 minute** (inline bytes OK).
    `BatchRecognize` accepts **only `gs://` Cloud Storage URIs** (no bytes field exists at the
    RPC level) — the doctrine-compliant long-audio path is therefore **chunked ≤60 s sync
    recognize**, which is what D builds (§9). Streaming (realtime-paced, 5-min streams) doesn't
    fit either. Dynamic batching ($0.003/min) is "fulfilled within 24 hours" — a future
    optimization behind a doctrine-exception ADR, not v1 (§16).
11. **Speech v2 misc.** Pricing $0.016/min (rounded up per second; per **channel** — always send
    mono; no v2 free tier). Adaptation is GA: `inline_phrase_set` phrases with boost 0–20,
    chirp_3 dictionary up to 1,000 phrases. v2 auth documents only ADC/service accounts — **no
    API keys** (the current v1 API-key usage is doc-unbacked; D migrates speech to the
    JWT-bearer flow). Regional access = `{region}-speech.googleapis.com` +
    `projects/{p}/locations/{region}/recognizers/_` (implicit recognizer `_` is official; no
    recognizer resource management needed). Word-level timestamps on chirp_3 are
    doc-contradictory ("at-risk", §18) — D relies on **chunk-level** offsets it computes itself,
    not provider word offsets.

---

## 3. Architecture overview

All VLM code lives in `app/Platform/Enrichment/VlmVerification/`; speech changes live beside the
existing recognition code. Two new pipeline touchpoints, both dispatch-fast:

```
EnrichmentPipeline (per post, queue 'enrichment')
  hashtags → transcript → recognition† → keyframes → visual_match → VLM_VERIFICATION* → text_signals → …
                                                                      │ (dispatch-only stage)
                                                                      ▼
                                              VlmVerificationJob (async, queue qds.enrichment.vlm.queue)
                                                gates → build request → Gemini generateContent (EU)
                                                → validate/ground verdicts → band → VLM_PRODUCT rows
                                                → vlm_verification_runs + vlm_candidate_verdicts
                                                → AttributionService::enrich (re-classify)
  † recognition stage: speech sub-path upgraded (v2/chirp_3/EU/auto/hints, first ≤60 s sync as today);
    tiered posts additionally persist audio chunks + dispatch TranscribeExtendedAudioJob
                                                → appends transcript segments + SPOKEN_BRAND rows
                                                → AttributionService::enrich (re-classify)

qds:vlm-verify (scheduled sweep): unconsumed needs_verification flags → dispatch jobs;
                                  DEF-021 discovery → 'unverifiable' run rows (never sent to Gemini)
```

**Components** (each independently testable):

| Component | Responsibility |
|---|---|
| `VlmTriggerStage` (in `EnrichmentPipeline`) | Kill-switch + flag check on the run C just wrote; dispatches the job; stage marker |
| `VlmVerificationJob` | Async orchestrator: gates, request, call, persist, re-classify (§10) |
| `VlmRequestBuilder` | Frames (via C's `FramePreparation`), caption/transcript excerpts, candidate catalog, prompt + per-request schema (§6) |
| `GeminiVlmClient` | `generateContent` HTTP client on the EU rep endpoint; safety/finish-reason handling; error taxonomy; recorder + breaker wiring (§5) |
| `VerdictValidator` | Schema-echo + catalog grounding + frame-timestamp validation; malformed → bounded retry signal (§6) |
| `VlmBandMapper` (pure) | Verdicts + thresholds → AUTO/REVIEW/REJECT per candidate + run outcome (§7) |
| `VlmDetectionWriter` | DP-004-aware `VLM_PRODUCT` upsert, signals vocabulary (§7) |
| `VlmRunRecorder` | Append-only `vlm_verification_runs` + `vlm_candidate_verdicts` writes; consumption bookkeeping (§8) |
| `VlmVerifySweepCommand` | `qds:vlm-verify` — catch-up dispatch + DEF-021 `unverifiable` surfacing + backfill (§4/§14) |
| `GoogleServiceAccountTokenProvider` (generalized) | Parameterized (config block, cache key, source id); C's binding preserved (§5) |
| `GoogleSpeechV2Client` | v2 `recognize` on `eu-speech.googleapis.com`, chirp_3, auto language, adaptation (§9) |
| `AudioChunker` | ffmpeg segmented extraction (offset/length), first-chunk + persisted extension chunks (§9) |
| `SpeechAudioChunkWriter` / `speech_audio_chunks` | Persisted chunk artifacts for the async job; lifecycle + GDPR (§8/§12) |
| `TranscribeExtendedAudioJob` | Async chunk transcription, transcript stitching, SPOKEN_BRAND mining, re-classify (§9/§10) |

`MentionClassifier` is untouched. `AttributionService::buildEvidence` gets one deliberate,
switch-gated extension (§7). Everything else augments.

---

## 4. Trigger policy and the DEF-021 closure (approved Q1)

**The VLM runs ONLY on escalated posts — never every post.** Escalation set, exactly:

- The **latest** `visual_match_runs` row for a post has `needs_verification = true`
  (C's semantics: any REVIEW-band lone hit; or a no-band outcome — `no_match` *or*
  `inconclusive`, including the partial-embed-shortfall inconclusive — with an in-window
  shipment), **and**
- stored keyframes still exist (re-checked in the job; frames can be retention-pruned between
  flag and job), **and**
- no `vlm_verification_runs` row exists yet for that anchor run (**consumption bookkeeping** =
  the partial-unique index on `visual_match_run_id`, §8).

Two paths feed it: the **inline stage** (right after `visual_match` in the same pipeline run —
the fresh path, covers the common case immediately) and the **scheduled sweep** (`qds:vlm-verify`,
daily; catches posts enriched while D was off, backfills, and flag rows whose dispatch was lost).

**DEF-021 closed on D's side (no C changes).** The sweep — only when `visual_match.enabled` is on
(D requires C; the locked tier order stands) — additionally discovers shipped, in-window posts
whose visual outcome is **missing or skipped**: no `visual_match_runs` row at all (frameless,
no-creator, disabled-at-the-time) or a latest run with a `skipped_*` outcome. For each it writes a
`vlm_verification_runs` row with outcome **`unverifiable`** and a `trigger_reason` of
`unverifiable:no-run` / `unverifiable:skipped-run` — **never sent to Gemini** (zero frames =
nothing to look at; caption/transcript text is already mined by A). These rows exist for
observability, eval honesty, and review surfaces: "we could not look" is recorded as a fact,
never as product absence. C's append-only "a run row = a real match attempt" semantics stay
untouched.

**High-value definition & priority.** The escalation *is* the high-value gate (C only flags
REVIEW hits and shipment-backed misses). Budget priority mirrors C:
**high** when the anchor run's candidates include an ACTIVE/SHIPPING-campaign source
(`visual_match_candidates.source = 'roster'` or a shipment whose `seeding_campaign_id` is in an
ACTIVE/SHIPPING campaign — resolved from the persisted candidate rows), else **medium**.

---

## 5. The Gemini VLM provider

**Model.** `qds.enrichment.vlm.model_version` default **`gemini-3.5-flash`** (GA, EU-resident,
structured output; §2b.1). `gemini-3.1-flash-lite` is the documented cheap-tier config swap (same
API shape). The model id is config, stamped on every run row; changing it is a new
`model_version`, never a mutation. Do not reference preview models.

**Endpoint & residency.** `POST {base}/projects/{project}/locations/eu/publishers/google/models/{model}:generateContent`
with base default `https://aiplatform.eu.rep.googleapis.com/v1` (derived from
`services.google_vlm.location`, default `eu`, same derivation rule as C's embeddings client;
`global` is rejected — no residency guarantee). Config `services.google_vlm.*`:
`credentials_path`, `project_id`, `location`, `base_url` (override), `timeout` (default 60 s,
connect 10 s — VLM calls are slower than embeddings).

**Auth.** The C token provider is **generalized**: constructor takes (config block key, cache
key, source id) so `google_embeddings`, `google_vlm`, and `google_speech_v2` each get an instance
(credentials may point at the same service-account JSON). C's container binding keeps its exact
behaviour (regression-tested). Bearer token, key material never in URLs/logs/exceptions.

**Request envelope.** `contents[0].parts[]` = text part (prompt, §6) + one
`inlineData {mimeType, data}` part per prepared frame, each part carrying
`media_resolution: MEDIA_RESOLUTION_MEDIUM`  (exact field casing/nesting re-verified at
implementation against the INF reference — the §18 smoke task). `generationConfig`:
`responseMimeType: "application/json"`, `responseSchema` (§6), `temperature: 0`,
`maxOutputTokens` (config, default 2048), `thinking_level: LOW`. `safetySettings`: none sent
(defaults already OFF for this model family; CSAM/SPII filters are non-configurable anyway).
`AiPayloadGuard::assertSafe` runs on the full payload **before** token fetch; a guard rejection
is the approved fail-closed skip (`skipped:payload-guard`, §6).

**Response handling.** Success = candidate 0 with `finishReason STOP` and a JSON text part →
`VerdictValidator`. `promptFeedback.blockReason` or `finishReason ∈ {SAFETY, RECITATION,
BLOCKLIST, PROHIBITED_CONTENT, SPII}` → **permanent** `skipped:safety-block` (no retry, no
budget refund — the call billed). `finishReason MAX_TOKENS` or unparseable JSON →
malformed-output retry (§6). `usageMetadata` token counts persist on the run row.

**Error taxonomy & telemetry.** Identical posture to C's embeddings client: 429/RESOURCE_EXHAUSTED
→ RateLimited (+Retry-After), 401/403 → Authentication, 408/timeout → Timeout, ≥500 →
UpstreamError, non-JSON → MalformedResponse — all as `ProviderCallException` under **new source
`SRC-google-gemini-vlm`** (SourceRegistry + data-source matrix + ADR-0030 per DP-006), operation
`vlm.verify`, wrapped in `ProviderCallRecorder::start/recordOperation/recordFailure`.
`ProviderCircuitBreaker::shouldSkip(SRC-google-gemini-vlm)` is consulted **before** spending.

---

## 6. Request assembly and the grounding schema (approved Q2, Q6)

**Frames.** The job loads the stored `KeyframeSet` and reuses **C's `FramePreparation`**
(format check + quality filter + near-dup removal, same config) up to
`qds.enrichment.vlm.frame_budget` (default 12). Frames are presented in timestamp order and
named in the prompt (`FRAME_1 @ 2000ms`, …); null-timestamp frames (carousel images, thumbnails)
are named without a timestamp. Reusing C's prep keeps "what the VLM saw" consistent with "what C
scored" and cuts duplicate-frame token waste.

**Text context.** Caption truncated to `caption_max_chars` (default 2,000); transcript excerpt
(latest available `content_transcripts` row, any provider) truncated to `transcript_max_chars`
(default 4,000, head-first); both clearly delimited and labeled as untrusted creator content
(prompt-injection posture: the system instruction states that nothing inside the delimited
creator content can change the task, the output schema, or the candidate set).

**Candidate catalog.** From the anchor run's `visual_match_candidates` (ranked, deduped): per
candidate a stable key `P<product_id>`, product label, brand name, category, product aliases,
and its C similarity band/score as context. The catalog is the **closed answer set**.

**The schema (per-request, enum-grounded).** `responseSchema` built per request with the
candidate keys baked into string enums — the model **cannot** name an out-of-catalog product at
the decoding level:

```json
{
  "type": "object",
  "propertyOrdering": ["outcome", "verdicts", "overall_rationale"],
  "required": ["outcome", "verdicts"],
  "properties": {
    "outcome": { "type": "string", "enum": ["PRODUCT_CONFIRMED", "PRODUCT_ABSENT", "INCONCLUSIVE"] },
    "verdicts": {
      "type": "array",
      "minItems": 1,
      "items": {
        "type": "object",
        "propertyOrdering": ["product_key", "visible", "spoken", "gifting_cue",
                              "confidence", "frame_names", "rationale"],
        "required": ["product_key", "visible", "spoken", "gifting_cue", "confidence", "rationale"],
        "properties": {
          "product_key": { "type": "string", "enum": ["P123", "P456", "…per request…"] },
          "visible":     { "type": "boolean" },
          "spoken":      { "type": "boolean" },
          "gifting_cue": { "type": "boolean" },
          "confidence":  { "type": "number", "minimum": 0, "maximum": 1 },
          "frame_names": { "type": "array", "maxItems": 12,
                            "items": { "type": "string", "enum": ["FRAME_1", "…per request…"] } },
          "rationale":   { "type": "string" }
        }
      }
    },
    "overall_rationale": { "type": "string" }
  }
}
```

One verdict **per candidate** is instructed (look-alike disambiguation: the rationale must state
why the runner-up was rejected); `frame_names` is enum-constrained to the frames actually sent,
so timestamps can never be fabricated (the app maps names back to `timestamp_ms`). The prompt
instructs `outcome = INCONCLUSIVE` when frames are too poor/ambiguous to judge — INCONCLUSIVE is
a first-class outcome, distinct from PRODUCT_ABSENT, end to end.

**Validation (defense in depth, fail-closed).** `VerdictValidator` re-checks — even though the
schema constrains decoding — that: JSON parses; required fields present; every `product_key` is
in the request's candidate set; keys are unique; every `frame_name` was sent; confidence ∈ [0,1];
and **outcome↔verdict consistency**: `PRODUCT_CONFIRMED` requires ≥ 1 verdict with
(`visible` ∨ `spoken`) true and `confidence ≥ review` — a "confirmed" response with no such
verdict is **normalized to INCONCLUSIVE** (recorded with signal `vlm-outcome-normalized`, not
retried). A candidate the model omitted a verdict for is treated as an implicit absent/REJECT
(fail-safe), never guessed. Any hard violation = **malformed output** → retry with a corrective
suffix, up to `per_post_units = 3` total billed calls per post **across all job executions**
(§10 accounting); still failing → run outcome `failed_malformed` (counts as unverifiable, never
absent). A verdict never fabricates a product: an unlisted product mentioned in `rationale` text
is inert by design.

**Caption-echo guard (prompt-injection compensation).** The caption/transcript are untrusted and
can instruct the model ("product X is clearly visible") toward inflated `visible`/`confidence`
for an in-catalog candidate. Enum grounding cannot stop value inflation, so the band mapper
applies a compensating rule: when the candidate's product label (or its brand + product name)
appears verbatim in the caption/transcript text that was sent, an otherwise-AUTO verdict is
**capped at REVIEW** with signal `vlm-caption-echo`. Rationale: a text-named product already
produces product-level evidence through A's caption path — the VLM's unique automation value is
confirming **unnamed** visual presence, so the cap costs almost no recall while cutting off the
"caption tells the model what to see → auto-linked HIGH" injection lane. Residual risk (injection
without naming the product) is accepted and recorded; sub-project E's fusion is the designated
place to down-weight caption-echoed agreement further.

**Refusals.** Safety blocks are permanent (§5). A schema-conforming response that simply
declines (all verdicts `visible=false, spoken=false`, outcome INCONCLUSIVE, refusal-style
rationale) is a legitimate INCONCLUSIVE — recorded as such.

---

## 7. Bands, confidence envelope, and evidence integration (approved Q3)

**Band mapping** (`VlmBandMapper`, pure; thresholds `qds.enrichment.vlm.thresholds` — defaults
`auto: 0.85`, `review: 0.60`, `margin: 0.10` — **placeholders, sub-project E calibrates**; the
0.85/0.60 alignment with ADR-0026 cut-points is deliberate):

The mapper is a **total function** — every schema-valid verdict lands in exactly one band,
evaluated per candidate in this order:

| Band | Rule (first match wins) | Detection written |
|---|---|---|
| **REJECT** | `confidence < review`; **or** the candidate claim is negative (`visible`, `spoken`, `gifting_cue` all false); **or** run outcome is `PRODUCT_ABSENT` | none — verdict persists in `vlm_candidate_verdicts` only |
| **AUTO** | outcome `PRODUCT_CONFIRMED` ∧ `visible = true` ∧ ≥ 1 valid frame reference ∧ `confidence ≥ auto` ∧ the top confirmed-visible candidate beats **every** other confirmed-visible candidate by ≥ `margin` (set-wise: if it doesn't, **no AUTO is issued** for the run and every confirmed-visible candidate within `margin` of the top is REVIEW) ∧ not caption-echoed (§6 guard) | `VLM_PRODUCT` at **HIGH** |
| **REVIEW** | everything else with `confidence ≥ review` — i.e. confirmed but `confidence ∈ [review, auto)`; `visible = false` with `spoken`/`gifting_cue` true (product claimed but never seen — a visual grounding stage never auto-confirms unseen products); `visible = true` without a valid frame reference; margin-ambiguous; caption-echoed | `VLM_PRODUCT` at **LOW** |
| **INCONCLUSIVE** (run) | outcome INCONCLUSIVE (incl. §6 normalization), or `failed_malformed`, or safety/payload skip | none; run outcome recorded; **never** "product absent" |

**Detection rows** (`VlmDetectionWriter`, mirroring `VisualMatchWriter`):
- identity `(target, VLM_PRODUCT, provider_label = 'vlm-product:<productId>')` — one row per
  product per post, stable across re-runs; DP-004 (`HumanPrecedence::allowsAiUpdate`), seed-on-
  create identity fields, unique-violation reload — the house pattern verbatim;
- `detected_brand` = brand name (evidence flow requires it), `detected_product` = label,
  `product_id` set, assessment value = brand name; AUTO → `ConfidenceLevel::High`,
  REVIEW → `Low`; always `AI_ASSESSED`;
- `assessment.signals` (compact audit trail; full rationale lives in D's tables):
  `vlm-product-match:<label>`, `vlm-confidence:<0.00>`, `vlm-visible:<true|false>`,
  `vlm-spoken:<true|false>`, `vlm-gifting-cue:<true|false>`,
  `vlm-frame:t=<ms>ms` (validated frames, first 5),
  `vlm-threshold:auto=<0.00>:review=<0.00>:margin=<0.00>`, `vlm-model:<model_version>`,
  plus `vlm-caption-echo` (§6 guard fired) and `vlm-outcome-normalized` (§6 consistency rule
  fired) when applicable;
- provenance `{source: SRC-google-gemini-vlm, fetchedAt, sourceVersion: 'vlm-verification-v1'}`;
- a later re-verification (only possible after catalog/model change) finding REJECT where an
  `AI_ASSESSED` VLM row exists downgrades it to LOW + `vlm-support-withdrawn` (never deletes;
  human-touched rows untouched) — C's withdraw pattern.

**Evidence gate** (`buildEvidence` — the only attribution touch, exactly C's shape):

```php
$vlmEnabled = (bool) config('qds.enrichment.vlm.enabled');
// Rollback no-op: VLM_PRODUCT rows excluded entirely when D's switch is off.
if ($type === RecognitionType::VlmProduct && ! $vlmEnabled) { continue; }
// Product flows for VLM rows under the same precision gate as VISUAL_PRODUCT:
//   NOT (AI_ASSESSED && level ∈ {Low, Unknown})
// EvidenceBundle::productDoctrine = $textEnabled || $visualEnabled || $vlmEnabled;
```

Verified consequences, zero classifier changes: AUTO (HIGH) → product-level alignment →
`SEEDED`/`HIGH` **only** with strong relevance + in-window shipment (the classifier's existing
gates — the VLM never auto-confirms seeding); REVIEW (LOW) → brand flows, product withheld →
`SEEDED`/`MEDIUM` + `product-unconfirmed`, held for review, never auto-linked; human approval →
product unlocks on the next classification. VLM rows at LOW appear in the existing review queue
automatically (kind `recognition`); correction remains brand-only (DEF-014 inherited).

**Disagreement with C is not resolved by D.** If C wrote a REVIEW-band VISUAL_PRODUCT row and the
VLM says ABSENT, both records stand (C's detection keeps routing to review; D's verdict is
visible context in D's tables). Cross-signal reconciliation is sub-project E's fusion mandate —
D deliberately emits, never arbitrates.

---

## 8. Data model

All tenant-owned tables: `BelongsToTenant`, NOT NULL `tenant_id`, composite `(fk, tenant_id)`
FKs, XOR target CHECK — C's migration patterns verbatim.

### 8.1 `vlm_verification_runs` — one row per verification attempt-set (append-only)
| column | type | notes |
|---|---|---|
| id | bigint PK | |
| tenant_id | FK tenants | + `(id, tenant_id)` unique |
| content_item_id / story_id | nullable FKs | CHECK `num_nonnulls(...) = 1`; composite tenant FKs; indexes `(content_item_id, id)`, `(story_id, id)` |
| visual_match_run_id | nullable FK → visual_match_runs, nullOnDelete | the anchor; **partial UNIQUE where not null = consumption bookkeeping**; null only for `unverifiable:no-run` discovery rows, which get their own dedup: partial UNIQUE on `(owner, trigger_reason)` WHERE `visual_match_run_id IS NULL` — the daily sweep can never duplicate them |
| correlation_id | varchar(64) | |
| model_version | varchar(64) | e.g. `gemini-3.5-flash` |
| trigger_reason | varchar(40) | CHECK IN (`review-band`, `no-band-shipment`, `sweep-catchup`, `unverifiable:no-run`, `unverifiable:skipped-run`) |
| priority | varchar(10) | CHECK IN (high, medium) |
| frames_sent | smallint | |
| prompt_tokens / output_tokens / thinking_tokens | int nullable | from `usageMetadata` |
| attempts | smallint | billed calls (≤ per_post_units) |
| outcome | varchar(30) | CHECK IN (`confirmed`, `absent`, `inconclusive`, `unverifiable`, `failed_malformed`, `skipped_budget`, `skipped_read_only`, `skipped_provider`, `skipped_safety_block`, `skipped_payload_guard`, `skipped_no_frames`) |
| rejection_reason | varchar(100) nullable | |
| thresholds | jsonb | snapshot `{auto, review, margin}` |
| latency_ms | int | wall-clock across attempts |
| estimated_cost_micro_usd | int | attempts × price constant |
| created_at | | no updated_at (append-only) |

### 8.2 `vlm_candidate_verdicts` — per-candidate verdicts per run
`id, tenant_id (+composite FK), vlm_verification_run_id FK cascadeOnDelete (+composite FK),
product_id nullOnDelete (+composite FK), product_label varchar(255) (denormalized — audit
survives catalog edits), brand_label varchar(255), rank smallint, visible boolean, spoken
boolean, gifting_cue boolean, confidence numeric(5,4), frame_timestamps jsonb (validated, list
of ints or null entries for unstamped frames), rationale text, band varchar(15) nullable CHECK
IN (auto, review, reject), rejection_reason varchar(100) nullable, created_at`. Sub-project E's
"Gemini agreement" input reads from here.

### 8.3 `speech_audio_chunks` — persisted extension-chunk artifacts
`id, tenant_id, owner_type varchar / owner_id bigint (polymorphic, like keyframes), ordinal
smallint (1-based; chunk 0 is the in-pipeline sync pass and is never persisted), offset_ms int,
duration_ms int, storage_disk varchar(50), storage_path varchar(500)
(`tenants/{tenant}/audio-chunks/{platform}/{owner}/{ordinal}.flac`), byte_size int, checksum
char(64), status varchar(15) CHECK IN (pending, transcribed, failed), created_at, updated_at`;
partial-unique `(owner_type, owner_id, ordinal)`. Rows + blobs are deleted by the job after
successful transcription; a daily orphan prune (age > `qds.enrichment.speech.chunk_orphan_days`,
default 7) and `CreatorEraser` are the backstops (§12).

### 8.4 Plumbing migrations
1. `RecognitionType::VlmProduct = 'VLM_PRODUCT'` enum case + CHECK DROP/re-ADD (house pattern).
2. `SourceRegistry::GOOGLE_GEMINI_VLM = 'SRC-google-gemini-vlm'` (+ ADR-0030, DP-006).
3. The three tables above.
4. Config additions (§13) — no schema impact.

No pgvector involvement — D stores no vectors.

---

## 9. Multilingual speech upgrade (approved Q4)

**Client swap, same shape.** `GoogleSpeechV2Client` replaces the v1 client behind the existing
`RecognitionService` seam when `qds.enrichment.speech.v2_enabled` is on:
`POST https://eu-speech.googleapis.com/v2/projects/{project}/locations/eu/recognizers/_:recognize`
(implicit recognizer `_`; location config default `eu`; endpoint derived
`{location}-speech.googleapis.com`), service-account Bearer auth (generalized token provider,
config `services.google_speech_v2.*`), body:

```json
{
  "config": {
    "model": "chirp_3",
    "languageCodes": ["auto"],
    "autoDecodingConfig": {},
    "features": { "enableAutomaticPunctuation": true },
    "adaptation": { "phraseSets": [{ "inlinePhraseSet": { "phrases": [
        { "value": "<brand or product name>", "boost": 10 } ] } }] }
  },
  "content": "<base64 FLAC chunk>"
}
```

(Exact REST field casing re-verified at implementation — §18 smoke task.) Phrase hints: the
tenant's brand names + aliases + candidate product names for the post's creator (via the same
candidate scoping C uses), deduped, capped at `phrase_cap` (default 500, hard model limit
1,000), boost `qds.enrichment.speech.boost` (default 10, range 0–20). Detected language per
result is captured. Mono FLAC in, always (per-channel billing).

**Chunked long audio, tiered (doctrine-compliant).**
- `AudioChunker` (extends the `AudioExtractor` posture: fixed argv, `-nostdin`, timeout,
  ≤7 MB/chunk guard): chunk `i` = ffmpeg `-ss <i·chunk_seconds> -t <chunk_seconds>` mono 16 kHz
  FLAC; `chunk_seconds` default **55** (safety margin under the 60 s/10 MB sync limit).
- **Chunk 0 (0–55 s) stays synchronous in-pipeline** — same latency as today's single call, now
  multilingual, through the budget guard (`speech_transcription`, 1 unit). Be precise about the
  cost change: v2 has **no free tier** (v1 granted 60 min/month free) and chunk 0 is newly
  metered for **every** audio-bearing post tenant-wide the moment the v2 switch turns on —
  $0.016/min from the first second. This is a new always-on floor, not a rate swap; the
  plan-page row states it as a per-audio-post floor (§11).
- **Extension (chunks 1..N)** only when the post is **candidate-bearing** (the anchor
  `CandidateScope` union is non-empty — in-window shipment or ACTIVE/SHIPPING roster) and the
  video is longer than `chunk_seconds`: chunks are persisted (`speech_audio_chunks`) during the
  pipeline (while the video temp file exists) up to `max_minutes` (default 10), and
  `TranscribeExtendedAudioJob` is dispatched. Non-candidate posts never pay for extension.
- The job (async): per pending chunk → budget `allows` → v2 recognize → SPOKEN_BRAND mining →
  chunk `transcribed` + blob deleted; after the last chunk → transcript stitch + one
  `AttributionService::enrich` re-classification. Transient provider failure → release/backoff
  (chunk stays `pending`); permanent → chunk `failed` + marker, remaining chunks still attempted.
- Stage markers (recognition stage sub-markers, additive): `speech:budget-exhausted`,
  `speech:chunks-queued=N`, `speech:v2-not-configured` (falls back to legacy v1 path only when
  the v2 switch is off — when on but unconfigured, speech skips fail-closed, like C).

**Transcript persistence (content items).** One `content_transcripts` row per content item under
provider `SRC-google-speech-to-text`: `language` = dominant detected language (most billed
seconds; per-chunk languages preserved in segments), `status = available`, `segments` =
`list<{start, dur, text, language, chunk}>` (chunk-level offsets D computes — provider word
offsets are at-risk, §2b.11), `text` = stitched full text, checksum, provenance. Upserted on the
unique `(content_item_id, language, provider)` key; the sync chunk writes/updates it first, the
job appends. **Stories** keep detections-only (no transcript table for stories today —
documented v1 limitation, §16). The transcript row is exactly what D's own VLM request (§6) and
A's future text mining consume.

**SPOKEN_BRAND detections** flow through the **existing** normalizer
(`speechBatch`-equivalent per chunk: top alternative, `textCandidate(SpokenBrand, …)`,
provider_label = truncated chunk transcript — per-chunk identity means later-in-video mentions
become their own rows), provenance source `SRC-google-speech-to-text`,
sourceVersion `google-speech-to-text-v2`. Brand-level only, as today — product-level speech
claims are the VLM's job (§7 caps them at REVIEW).

**Rollback.** `speech.v2_enabled = false` (default) → the v1 client path runs byte-identically
to today (de-DE, ≤60 s, API key, global endpoint, no transcript rows, no chunks, no budget
gate). The switch covers client, tiering, persistence, and budget — a true no-op.

---

## 10. Async jobs, sweep, and failure semantics (approved Q5)

**`VlmVerificationJob`** (`ShouldBeUnique` on `vlm-verify:{content|story}:{id}`, queue
`qds.enrichment.vlm.queue` default `enrichment`, tries 4, timeout 180, `IngestionJobBehaviour`,
`TenantContext::runAs`):
1. Re-check gates in order, each with an explicit budget side effect (fixing what an implementer
   would otherwise guess):

   | Gate | Terminal outcome | Budget side effect |
   |---|---|---|
   | kill switch off / target or anchor gone | none (silent no-op — feature dark or stale job) | none |
   | already consumed (run row exists for anchor) | none (idempotency no-op) | none |
   | provider not configured / breaker open | `skipped_provider` | none (run row only) |
   | frames gone (pruned since flag) | `skipped_no_frames` | none (run row only) |
   | read-only mode | `skipped_read_only` | none (run row only) |
   | budget deny | `skipped_budget` | `record(0, postsSkippedBudget: 1)` |
   | payload guard trip | `skipped_payload_guard` | none (run row only) |

2. Call loop, with **cross-execution billing accounting**: before attempt *n* (1-based,
   counting every billed call this post has ever made — attempts are persisted on the terminal
   run row and, within an execution, in memory) the job calls
   `allows('vlm_verification', tenant, n, priority)` — passing the **cumulative attempt count
   as units** so the guard's `units > per_post_units` per-post ceiling actually binds (C passes
   an aggregate projection for the same reason; a flat `allows(1)` would never trip it). Each
   billed attempt then calls `record(1, postsProcessed: attempt 1 only)` and is telemetered
   (`ProviderCallRecorder`, op `vlm.verify`). Validator-driven retries (§6) continue the same
   loop, ≤ `per_post_units` total billed calls per post.
3. Persist: run row + verdicts (append-only) → detections (band-gated) →
   `AttributionService::enrich($target)` — the mention updates in the same tenant context
   (backfill precedent).
4. Failure semantics (designed so job-level `tries` can never multiply the per-post billing
   ceiling):
   - `ProviderCallException` **transient, before any billed call this post**: no run row →
     `handleProviderFailure` (Retry-After release / backoff) — the retry is free by
     construction.
   - **Transient after ≥ 1 billed call**: do **not** release — write the terminal run row with
     outcome `skipped_provider` (attempts + cost recorded; C's mid-run transient posture).
     The post is consumed; if it still matters, a later catalog/model change or operator
     re-open is the path, never silent re-billing.
   - Safety block: terminal `skipped_safety_block`; the blocking attempt **was billed** and is
     already in `record(1)`/attempts (HTTP 200 responses bill, §2b).
   - Permanent categories (auth, malformed after retries) → `failed()` → JobFailed critical
     alert + terminal run row (`failed_malformed` where applicable).
   **A VLM failure never fails or blocks any enrichment run** — fail-closed skip is the worst
   case, evidence stays absent, the mention stands wherever the cheaper tiers put it.

**`TranscribeExtendedAudioJob`** — same shape (`ShouldBeUnique` on `speech-ext:{owner}:{id}`,
tries 4, timeout 300), chunk loop as §9.

**`qds:vlm-verify {--days=30} {--tenant=} {--dry-run}`** (scheduled daily; self-gated on both
`vlm.enabled` and `visual_match.enabled`): (a) latest flagged-but-unconsumed visual runs in the
window → dispatch `VlmVerificationJob` per target (counts printed; budget enforcement happens in
the jobs); (b) DEF-021 discovery (§4) → direct `unverifiable` rows (no jobs, no spend);
(c) `--dry-run` prints both sets without writing. Also the day-one backfill tool (§14).

**Queue posture.** Both jobs default to the existing `enrichment` queue; the name is env-tunable
(`qds.enrichment.vlm.queue`, `qds.enrichment.speech.queue`) so a dedicated `ai` queue with its
own workers — and DEF-017's future off-peak lane — is a config flip, not a code change.

---

## 11. AI budget governance (approved Q1b)

Two new capability blocks in `qds.ai_budget.capabilities` (the guard, alerts at 50/80/95/100 %,
`qds:ai-quota` overrides, read-only mode, and the ops-dashboard panel all come free — the panel
iterates capability config):

| | `vlm_verification` | `speech_transcription` |
|---|---|---|
| unit | 1 Gemini request | 1 audio chunk (≈ 1 min) |
| price_micro_usd_per_unit | **30_000** ($0.030 — realistic range $0.026–0.035: ~9.5–10 k input tokens (12 frames × 560 dominate, plus caption/transcript/catalog/schema) @ $1.65/M + up to ~2 k output incl. LOW thinking tokens @ $9.90/M; rounded up so caps aren't systematically loose) | **16_000** ($0.016/min, §2b.11) |
| per_post_units | 3 (1 call + ≤2 validator retries) | 10 (= max_minutes) |
| tenant_daily / monthly | 150 (~$3.75) / 3_000 (~$75) | 300 / 6_000 |
| global daily / hard | 1_500 / 3_000 | 3_000 / 6_000 |
| global monthly / hard | 30_000 (~$750) / 60_000 (~$1,500) | 60_000 / 120_000 |

Priority per §4 (VLM) and C's candidate rules (speech extension); **high** bypasses tenant soft
caps and stops only at global hard caps / read-only / breaker — identical semantics to C. Two
deliberate properties, stated explicitly:
- **Daily = burst, monthly = sustained.** Tenant 150/day × 30 > 3,000/month by design: the daily
  figure allows campaign-launch bursts, the monthly cap is the true sustained throttle
  (~100/day average). The same relationship holds for the global dimensions.
- **Cross-tenant fairness is the global hard cap, accepted.** One tenant's HIGH-priority storm
  (a big ACTIVE campaign) can exhaust the global soft pool — denying every tenant's MEDIUM work
  — and keep spending to the global hard cap. This is C's inherited semantics, accepted for v1
  with eyes open: the 50/80/95/100 % global threshold alerts surface it, `qds:ai-quota` can
  clamp the offender, and `qds:ai-read-only` is the brake. A per-tenant HIGH ceiling is a
  registered follow-up (§16), not built.

Price constants are **estimates for governance**, not billing truth; the ops dashboard's spend
numbers carry the same caveat they do for embeddings today.

**Plan page** (`IngestionCostEstimator::perService`): the "Spoken brand mentions" row is updated
(v2 rate, tiered-minutes assumption, active-flag = enrichment + v2 switch + v2 credentials) and
a new "VLM verification (Gemini)" row is added (assumption constants documented as estimates;
active-flag = enrichment + vlm switch + vlm credentials + visual-match on). **Ops dashboard**
additionally gains a `vlmRunAggregates()` panel over recent `vlm_verification_runs` (runs,
outcome breakdown, attempts/post, avg latency, budget denials, unverifiable count) mirroring
`visualRunAggregates()`.

---

## 12. GDPR and EU residency (approved Q6)

| Data | Sent to | Residency |
|---|---|---|
| Keyframes (creator media), caption, transcript excerpts, candidate labels | Gemini `generateContent` | `aiplatform.eu.rep.googleapis.com` — ML processing within EU member states (§2b.2) |
| Audio chunks (creator voice) | Speech v2 `recognize` | `eu-speech.googleapis.com` + `locations/eu`; contractual coverage via the Google Cloud data-residency terms (Cloud Speech-to-Text is on the AI/ML Data Location list); the explicit v2 residency sentence is a §18 watch item |

The speech move **partially closes DEF-020** (Speech leaves the global endpoint; Vision/Video
Intelligence remain global — DEF-020 stays open for them, amended). Payload rule: captions/
transcripts pass `AiPayloadGuard` un-redacted or the call is skipped (`skipped_payload_guard` —
approved fail-closed posture; a scrubber is a documented follow-up if telemetry shows it biting).

**Lifecycle:**

| Data | Creator-GDPR erase | Retention |
|---|---|---|
| `vlm_verification_runs` / `_verdicts` | Yes — added to `CreatorEraser`'s ordered delete list (runs before content; verdicts cascade) + erasure-test extension | with content |
| `speech_audio_chunks` rows + blobs | Yes — rows in-transaction, blobs after commit (house order) | deleted on successful transcription; orphan prune daily (`chunk_orphan_days` = 7) |
| Speech `content_transcripts` rows | Already covered (existing eraser path by content id) | with content |
| `VLM_PRODUCT` detections + review actions | Already covered (`recognition_detections`) | with content |
| Budget counters | No (operational, no personal data) | telemetry prune (existing) |

GDPR export continues to exclude derived AI data (existing precedent). Google enterprise-platform
processing falls under the Cloud Data Processing Addendum (no consumer Gemini API anywhere —
rejected for residency, §2b.2). No append-only triggers on D's tables in v1 (C's precedent,
noted for the future hardening pass).

---

## 13. Configuration reference

```php
// config/qds.php → enrichment block
'vlm' => [
    'enabled' => (bool) env('QDS_ENRICHMENT_VLM_ENABLED', false), // kill switch — true no-op
    'model_version' => env('QDS_ENRICHMENT_VLM_MODEL', 'gemini-3.5-flash'),
    'queue' => env('QDS_ENRICHMENT_VLM_QUEUE', 'enrichment'),
    'frame_budget' => (int) env('QDS_ENRICHMENT_VLM_FRAME_BUDGET', 12),
    'media_resolution' => env('QDS_ENRICHMENT_VLM_MEDIA_RESOLUTION', 'MEDIA_RESOLUTION_MEDIUM'),
    'thinking_level' => env('QDS_ENRICHMENT_VLM_THINKING_LEVEL', 'LOW'),
    'max_output_tokens' => (int) env('QDS_ENRICHMENT_VLM_MAX_OUTPUT_TOKENS', 2048),
    'caption_max_chars' => (int) env('QDS_ENRICHMENT_VLM_CAPTION_MAX_CHARS', 2000),
    'transcript_max_chars' => (int) env('QDS_ENRICHMENT_VLM_TRANSCRIPT_MAX_CHARS', 4000),
    'thresholds' => [ // placeholders — sub-project E calibrates
        'auto' => 0.85, 'review' => 0.60, 'margin' => 0.10,
    ],
],
'speech' => [
    'v2_enabled' => (bool) env('QDS_ENRICHMENT_SPEECH_V2_ENABLED', false), // kill switch — off = exact legacy v1 path
    'model' => env('QDS_ENRICHMENT_SPEECH_MODEL', 'chirp_3'),
    'language_codes' => ['auto'], // override with explicit list via config only
    'queue' => env('QDS_ENRICHMENT_SPEECH_QUEUE', 'enrichment'),
    'chunk_seconds' => (int) env('QDS_ENRICHMENT_SPEECH_CHUNK_SECONDS', 55),
    'max_minutes' => (int) env('QDS_ENRICHMENT_SPEECH_MAX_MINUTES', 10),
    'boost' => (float) env('QDS_ENRICHMENT_SPEECH_BOOST', 10.0),   // 0–20
    'phrase_cap' => (int) env('QDS_ENRICHMENT_SPEECH_PHRASE_CAP', 500), // model hard limit 1000
    'chunk_orphan_days' => (int) env('QDS_ENRICHMENT_SPEECH_CHUNK_ORPHAN_DAYS', 7),
],

// config/qds.php → ai_budget.capabilities (replaces the reserved comment)
'vlm_verification' => [ /* §11 values, each env-overridable QDS_AI_VLM_* */ ],
'speech_transcription' => [ /* §11 values, each env-overridable QDS_AI_SPEECH_* */ ],

// config/services.php — model-based naming (C's precedent)
'google_vlm' => [
    'credentials_path' => env('GOOGLE_VLM_CREDENTIALS'), // may equal GOOGLE_EMBEDDINGS_CREDENTIALS
    'project_id' => env('GOOGLE_VLM_PROJECT'),
    'location' => env('GOOGLE_VLM_LOCATION', 'eu'),
    'base_url' => env('GOOGLE_VLM_BASE_URL'), // default derived: aiplatform.eu.rep.googleapis.com/v1
    'timeout' => (int) env('GOOGLE_VLM_TIMEOUT_SECONDS', 60),
],
'google_speech_v2' => [
    'credentials_path' => env('GOOGLE_SPEECH_V2_CREDENTIALS'),
    'project_id' => env('GOOGLE_SPEECH_V2_PROJECT'),
    'location' => env('GOOGLE_SPEECH_V2_LOCATION', 'eu'),
    'base_url' => env('GOOGLE_SPEECH_V2_BASE_URL'), // default derived: eu-speech.googleapis.com/v2
    'timeout' => (int) env('GOOGLE_SPEECH_V2_TIMEOUT_SECONDS', 60),
],
```

The existing `services.google_speech` (v1) block is untouched — it is the rollback path.

---

## 14. Rollout and backfill

- **Ship dark.** Both switches default OFF: the vlm stage records `skipped:disabled` and
  dispatches nothing; speech runs the byte-identical v1 path; the sweep exits immediately;
  evidence is byte-identical to today (tested, §15).
- **Enable order:** visual_match (C) must already be on for the VLM (D requires C, §4). Speech
  v2 is independent.
- **Go-live needs:** service-account credentials/project for `google_vlm` + `google_speech_v2`
  (may reuse the embeddings key), then one **smoke task** each: a real `generateContent` call on
  the `eu` rep endpoint (schema round-trip, field casing) and a real v2 `recognize` on
  `eu-speech.googleapis.com` (chirp_3 + `["auto"]` + inline adaptation) — pin observed model
  ids/prices/limits then (§18 mandate).
- **Backfill = the sweep.** `qds:vlm-verify --days=N` is the day-one tool: it dispatches
  verification for every flagged-but-unconsumed run in the window through the normal budget
  guard (a backfill cannot blow the budget) and surfaces the DEF-021 `unverifiable` set.
  No separate backfill command needed.

---

## 15. Eval extension and testing strategy

**Eval (`qds:eval-detection`).** Cases gain an optional `vlm` block, scored through the **real**
`VerdictValidator` + `VlmBandMapper` (pure, no network/DB — the C precedent):

```json
"vlm": {
  "candidates": [{"product": "Nexon Labs Headset", "brand": "Nexon Labs", "category": "TECH"}],
  "frames": [{"name": "FRAME_1", "t_ms": 1500}],
  "verdict_fixture": {
    "outcome": "PRODUCT_CONFIRMED",
    "verdicts": [{"product_key": "P1", "visible": true, "spoken": false, "gifting_cue": true,
                   "confidence": 0.91, "frame_names": ["FRAME_1"], "rationale": "…"}]
  },
  "expected": {"product": "Nexon Labs Headset", "band": "auto"},
  "look_alike": false
}
```

Reported for the **escalated subset**: product-level precision/recall, look-alike
disambiguation accuracy (`look_alike: true` cases — wrong-candidate picks counted), band
distribution + bands-as-expected, malformed-fixture handling (validator rejects count), and
**provider cost per case** (frames × MEDIUM tokens + text estimate × the §11 price constant).
Speech: multilingual transcript fixture cases (DE/EN/FR captions with brand mentions) asserting
`SPOKEN_BRAND` mining through the lexicon path, plus a stitching fixture (chunk boundary,
detected-language mix → dominant-language row). Existing text/visual metrics untouched.

**Tests (TDD, PHPUnit, base `Tests\TestCase`, `RefreshDatabase`, factories/fixtures,
`XDEBUG_MODE=off`).** Highlights:
- `VlmBandMapper` pure: every band boundary, **totality** (every schema-valid verdict maps to
  exactly one band — incl. all-false confirmed verdicts, visible-without-frames, missing
  `frame_names`), visibility requirement (spoken-only caps REVIEW), set-wise margin ambiguity
  (3+ clustered candidates → no AUTO, all within margin REVIEW), caption-echo cap,
  INCONCLUSIVE vs ABSENT, threshold config resolution, determinism.
- `VerdictValidator`: schema echo, out-of-catalog key, duplicate keys, unknown frame name,
  confidence range, outcome↔verdict consistency (confirmed-empty → INCONCLUSIVE
  normalization), implicit-absent for omitted candidates, retry-signal contract, fabrication
  inertness.
- `GeminiVlmClient`: `Http::fake` request-shape tests (EU URL, schema injection, media_resolution
  per part, thinking_level, no safetySettings), safety-block permanence, MAX_TOKENS → retry
  path, error taxonomy, recorder/breaker wiring, `AiPayloadGuard` compliance (payload-guard trip
  → skip outcome).
- `VlmVerificationJob`: full gate matrix (each skip outcome + its §10 budget side effect),
  consumption idempotency (unique anchor; no-run discovery dedup), budget deny, cumulative
  `allows(n)` per-post ceiling binding, **cross-execution billing cap** (transient-after-billed
  → terminal `skipped_provider`, never released; transient-before-billed → free release),
  attribution re-run, uniqueness.
- `VlmDetectionWriter`: DP-004 matrix, provider_label idempotency, withdraw path, signals
  vocabulary, unique-violation recovery.
- `VlmVerifySweepCommand`: flagged-unconsumed dispatch, DEF-021 discovery (no-run + skipped-run
  → `unverifiable` rows, never a Gemini call), gating on both switches, `--dry-run` purity,
  tenant isolation (`makeTenantPair`).
- `buildEvidence` gate matrix: **vlm switch off ⇒ evidence byte-identical**; LOW-gate; triple-OR
  productDoctrine; end-to-end classifier outcomes (AUTO→HIGH SEEDED with in-window shipment;
  REVIEW→MEDIUM `product-unconfirmed`; approval unlock; no-shipment→LIKELY_ORGANIC).
- Speech: `GoogleSpeechV2Client` request shape (EU URL, chirp_3, `["auto"]`, adaptation
  phrases/boost, mono flac inline), detected-language capture; `AudioChunker` real-ffmpeg
  tests (chunk count/offsets/≤7 MB, determinism — `AudioExtractorTest` pattern);
  tier decision (candidate-bearing × duration); transcript stitching + dominant language +
  segments shape; per-chunk SPOKEN_BRAND identity; `TranscribeExtendedAudioJob` chunk loop /
  partial failure / blob deletion / attribution re-run; **v2 switch off ⇒ v1 request
  byte-identical** (characterization); budget-deny markers.
- GDPR: eraser extension (runs/verdicts/chunks + blobs), orphan prune, transcript coverage.
- Eval: vlm + speech fixtures, cost output, existing metrics unchanged.

---

## 16. Deferred, documented, and doc amendments

**New deferred-register entries:** GCS-staged `BatchRecognize` (dynamic batch $0.003/min +
diarization; needs a DP-005 exception ADR + EU bucket — revisit if chunk-boundary losses show up
in eval); reference-photo-in-prompt VLM verification (v2, §17); story transcript persistence
(polymorphic `content_transcripts` owner); per-segment code-switching (undocumented in chirp_3 —
watch item); caption PII scrubber (only if `skipped_payload_guard` telemetry shows it biting);
per-tenant HIGH-priority ceiling in `AiBudgetGuard` (the §11 accepted cross-tenant fairness
gap); Vision/Video-Intelligence EU endpoints (DEF-020 amended: speech closed, those remain).

**Amended entries:** DEF-021 (closed by D's sweep discovery — resolution recorded); DEF-020
(speech portion resolved by D); DEF-017 (queue name knobs land; the off-peak lane itself still
deferred).

**ADR-0030 — "VLM grounding & multilingual speech (sub-project D)":** records
`SRC-google-gemini-vlm` (DP-006 amendment), the `gemini-3.5-flash` EU pin + why no Pro-class
model was possible, enum-grounded structured output, the band/visibility doctrine, the DEF-021
closure design, speech v1→v2 migration (EU move, chirp_3, auto-detect, phrase hints,
chunked-inline long audio, GCS rejection), dual kill switches, and the two budget capabilities.

**Doc amendments on landing:** glossary `ENUM-RecognitionType` (+`VLM_PRODUCT`, ADR-0030 cite);
`seeded-product-detection.md` §2 (stage line), §3b (speech multilingual), new §3g (VLM signal),
§11 (config), §12 (de-DE/60 s limitation removed; VLM limitation updated), §13 (code map);
roadmap status table (D ✅) + E prompt note (verdicts table = the "Gemini agreement" input);
data-source matrix §2.2/§3/§4 (VLM entry per the C template; speech row updated to v2/EU/
multilingual); module docs stage sequences.

---

## 17. v2 — designed-for, not built

- **Reference photos in the prompt.** `VlmRequestBuilder` is the seam: adding 1 photo per
  candidate (`inlineData` parts, +560 tokens each) needs no schema change — a
  `sourceVersion: 'vlm-verification-v2'` bump and a config flag.
- **Cheap-tier routing.** `gemini-3.1-flash-lite` for medium-priority verifications (model per
  priority) — config-shaped (`model_version` per priority map), not built.
- **Off-peak lane.** Queue knobs exist (§10); DEF-017's scheduled off-peak worker attaches
  without code changes to D.
- **GCS batch speech** for >10-min audio at $0.003/min — behind the doctrine-exception ADR (§16).

---

## 18. External-dependency verification (2026-07-20)

Per the external-verification mandate: every external claim above was researched by **two
independent passes per domain** against official Google documentation only, then **adversarially
reconciled** (every discrepancy re-fetched; the five most load-bearing claims per domain
re-verified even where the passes agreed). Key verified values are inline in §2b. Full URL sets:

**Gemini (Gemini Enterprise Agent Platform, formerly Vertex AI — renamed 2026-04-22, API service
`aiplatform.googleapis.com` unchanged):**
model cards `docs.cloud.google.com/gemini-enterprise-agent-platform/models/gemini/{3-5-flash,3-1-pro,3-1-flash-lite,3-flash}` ·
model-versions (lifecycle/retirements) · release-notes · resources/data-residency ·
resources/locations · models/capabilities/control-generated-output (structured output) ·
reference/models/inference (generateContent) · reference/models/function-calling ·
models/capabilities/image-understanding (media_resolution tokens; Preview-marked) ·
models/start/get-started-with-gemini-3 (thinking_level) ·
models/capabilities/configure-safety-filters · models/capabilities/batch-inference ·
models/standard-paygo (usage tiers) · models/quotas · models/start/api-keys ·
`cloud.google.com/gemini-enterprise-agent-platform/generative-ai/pricing` ·
`ai.google.dev/gemini-api/terms` (consumer API residency — rejected).

**Speech-to-Text v2:**
`docs.cloud.google.com/speech-to-text/v2/docs/{transcription-model,multiple-languages,quotas,batch-recognize,recognizers}` ·
v2 RPC reference `…/v2/docs/reference/rpc/google.cloud.speech.v2` (Recognize limits; BatchRecognize
`uri`-only input union; inline output one-file restriction; adaptation/boost; WordInfo
"experimental") · models/chirp-3 (GA, `eu` GA, `["auto"]`, 1,000-phrase dictionary, denoiser) ·
docs/release-notes (chirp_3 GA 2025-10-13; newest entry 2025-11-13) · docs/migration (no v1
sunset) · docs/endpoints (regional endpoints + residency sentence — v1 page) ·
docs/authentication (ADC only, no API keys) · `cloud.google.com/speech-to-text/pricing`
($0.016/min; per-channel; dynamic batch $0.003/min "within 24 hours") ·
`cloud.google.com/terms/data-residency` (Cloud Speech-to-Text on the AI/ML list) ·
free-tier page (v1-only 60 min).

**Unverified / watch items (flagged, not built upon):** total per-request inline byte cap for
`generateContent` (undocumented; D's ~12 frames ≪ any plausible cap); Gemini 3 image
tokenization is itself Preview-marked (budgets may change — cost constants are config);
retirement dates for the 3.x preview models (unused); explicit v2 wording of the speech
residency sentence (contractual terms + regional endpoints carry it); chirp_3 word-level
timestamps (contradictory docs — unused, chunk offsets are computed locally); chirp_3 billing
SKU name (presumed standard Recognition tier); API keys on Speech v2 (treated as unsupported);
speech release-note staleness (8 months quiet — §14 smoke tasks re-pin at implementation).

**Re-verify at implementation time (dedicated early plan tasks):** one real `generateContent`
smoke call on the `eu` rep endpoint (schema round-trip, exact part-level `media_resolution` and
`thinking_level` field casing, usageMetadata shape) and one real v2 `recognize` call on
`eu-speech.googleapis.com` (field casing, `["auto"]` behaviour, adaptation shape); pin model
ids, prices, and limits observed then.
