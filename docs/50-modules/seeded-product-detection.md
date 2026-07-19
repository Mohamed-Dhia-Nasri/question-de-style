# Seeded-Product Detection

> **What this document is.** A complete, implementation-level description of how QuestionDeStyle
> decides that a creator's public post shows a **product the brand gifted (seeded)** to that creator,
> and attributes it to the documented gift. Read this to understand the detection end-to-end before
> changing any of it. Design rationale/history lives in
> `docs/superpowers/specs/2026-07-18-seeded-detection-tier0-free-signals-design.md`; the doctrine ADR
> is `ADR-0008`; tenancy is `ADR-0019`/`ADR-0020`.

---

## 1. Purpose & vocabulary

Brands send creators free products ("seeding" / PR gifts). The platform detects when a monitored
creator then posts that product and labels the post so the brand can measure earned media. The
classifier assigns every relevant post exactly one **mention type** (`App\Shared\Enums\MentionType`):

| Mention type | Meaning | Requires |
|---|---|---|
| `SEEDED` | The post was caused by a documented gift | A **shipped** product + independent relevance + timing (see §5) |
| `PAID` | A platform paid-partnership / branded-content label is present | The platform's own disclosure label |
| `LIKELY_ORGANIC` | The brand/product appears but no gift record links it | Strong relevance, no aligned shipment |
| `UNKNOWN` | Weak / ambiguous / conflicting evidence — routes to a human | — |

There is deliberately **no "confirmed organic"** — without a proving record the strongest negative
claim is `LIKELY_ORGANIC` (ADR-0008). A post with no brand/product reference at all creates **no
mention record**.

Everything below runs **per tenant** (see §7): each tenant classifies its own copy of a post using
only its own brands, products, and gift records.

---

## 2. The end-to-end pipeline

Detection is one stage sequence inside the enrichment service, run once per piece of content by
`App\Platform\Enrichment\EnrichmentPipeline::run()`:

```
Ingestion (per tenant, per monitored creator)
   │  writes ContentItem / Story with caption, media URLs, and the free structured signals
   ▼
EnrichmentPipeline  (App\Platform\Enrichment\EnrichmentPipeline)
   hashtags → transcript → recognition → keyframes → VISUAL MATCH → text-signals → sentiment → SEEDED ATTRIBUTION → EMV → reach
   │            │             │                              │
   │            │             │                              └─ AttributionService: assemble evidence, classify, upsert the Mention
   │            │             └─ TextSignalRecognizer: mine caption + platform tags → RecognitionDetection rows (kill-switch gated)
   │            └─ RecognitionService: media → Google Vision/Video/Speech → RecognitionDetection rows
   └─ HashtagEnricher: caption #tags → ContentHashtag rows matched to configured lists
   (transcript + keyframes: sub-project B, ADR-0028 — YouTube captions text and persisted ffmpeg
   frames; VISUAL MATCH: sub-project C, ADR-0029 — keyframes vs reference-photo embeddings →
   VISUAL_PRODUCT detections, §3f; each of these stages is kill-switched)
   ▼
SeededContentLinker  (scheduled, separate)
   materialises shipment ↔ content links from the SEEDED mentions produced above
```

Enrichment is dispatched **per data pull** (`PerPullEnrichmentDispatcher`, ADR-0023) and each run is
executed under the content's tenant: `EnrichContentItemJob` wraps the pipeline in
`TenantContext::runAs($contentItem->tenant_id, …)`.

---

## 3. The signals (what evidence can exist)

The classifier never looks at raw media or captions itself — upstream stages turn everything into two
tables it reads: **`recognition_detections`** (brand/product recognitions) and **`content_hashtags`**
(matched hashtags), plus the tenant's **shipments** (gift records). The signal sources:

### 3a. Structured platform signals (captured at ingestion, near-ground-truth)
Stored on `content_items` by `ContentItemPersister` from `ContentData` (mapped in the provider adapters
via `App\Platform\Ingestion\Normalization\SignalExtract`), fail-closed (absent → empty/null):

- `mentioned_handles` (jsonb) — the post's `@mentions`. *(Column is named `mentioned_handles`, not
  `mentions`, to avoid shadowing the `ContentItem::mentions()` relation, which returns attribution
  `Mention` rows.)*
- `product_tags` (jsonb) — platform shopping/product tags: `{brand_ref, product_name, product_sku, provider_tag_id}`.
- `collaborators` (jsonb) — brand/creator co-author handles.
- `branded_content_label` (boolean, **tri-state**) — `true` = confirmed paid, `false` = explicitly not,
  `null` = provider did not say. Only `true` drives `PAID`.

### 3b. Media recognition (existing, `RecognitionService` → Google providers)
Writes `recognition_detections` of type `LOGO`, `IMAGE_TEXT_OCR`, `ON_SCREEN_TEXT`, `SPOKEN_BRAND`.
Brand-level only (a logo/OCR/transcript hit is matched to a CRM brand via `BrandLexicon`; it is never
narrowed to a product). Google Cloud Vision (image OCR + logo), Video Intelligence (on-screen text +
logo), Speech-to-Text (de-DE). **Media recognition currently runs on Instagram media only**; see §12.

### 3c. Text signals (mined from the caption + structured tags, no external cost)
`App\Platform\Enrichment\TextSignals\TextSignalRecognizer` (gated by the kill switch, §8) writes
`recognition_detections` of these new types:

- `CAPTION_TEXT` — a CRM brand and/or product name found in the caption prose (via `BrandLexicon` and
  `ProductResolver`).
- `MENTION` — an `@handle` in the caption resolved to a CRM brand via `brands.social_handles`
  (`MentionExtractor` + `BrandLexicon::resolveHandle`).
- `PRODUCT_TAG` — a structured product tag resolved to a CRM brand **and product** (the strongest
  free product-level signal).

It also emits **gifting/PR cues** ("gifted", "PR-Paket", "offert", …, DE/EN/FR) via
`ContextualCueDetector` — these ride on the evidence bundle as `contextualCues` (a context/
explainability booster, never a standalone proof).

### 3d. Hashtags (`HashtagEnricher` → `content_hashtags`)
Caption `#tags` matched against configured campaign/brand/product/agency hashtag lists. Generic tags
(`#ad`, `#beauty`, …) never count. A tag matching several targets is **ambiguous** → review.

### 3e. Gift records (`ShipmentEvidenceSource` → `ShipmentEvidence`)
The tenant's documented seeding shipments for the creator. **Only dispatched shipments count**
(`shipped_at` is not null). Each carries `brandId/brandName`, `productLabel/productId`, `campaignId`,
`shippedAt`, `deliveredAt`.

### 3f. Visual product matching (sub-project C, `VisualProductMatcher`)
`App\Platform\Enrichment\VisualMatch\VisualProductMatcher` (gated by
`qds.enrichment.visual_match.enabled`, default OFF) matches B's stored keyframes against the
tenant's product **reference photos** (uploaded on `/crm/products`, embedded with Google multimodal
embeddings, stored in pgvector) and writes `recognition_detections` of type:

- `VISUAL_PRODUCT` — the product itself seen on screen (brand + product + `product_id`), at HIGH
  for the AUTO band (≥ 2 distinct-timestamp supporting frames above the category threshold plus a
  runner-up margin) or LOW for the REVIEW band (routes to human review; the §9 evidence gate
  withholds `product_id` until a human approves).

Candidates are scoped to the creator's plausible catalog only (in-window shipments + ACTIVE/SHIPPING
roster primaries — an empty candidate set costs nothing); every run and its ranked candidate scores
persist in `visual_match_runs` / `visual_match_candidates`, and `needs_verification` flags the
posts sub-project D's VLM verifier should look at (ADR-0029).

---

## 4. How a post is classified — the decision engine

`App\Platform\Enrichment\Attribution\AttributionService::enrich()` runs per active **creator**
monitored-subject for the target, assembles an **`EvidenceBundle`** (`buildEvidence`), and calls the
**pure** `MentionClassifier::classify()`. The result is upserted as a `Mention`
(`App\Modules\Monitoring\Models\Mention`), never overwriting a human decision (DP-004, §9).

### The `EvidenceBundle` (the classifier's whole input)
`App\Platform\Enrichment\Attribution\EvidenceBundle`:
- `recognitions` — `[{type, brand, level, productId?, product?}]` from `recognition_detections`
  (human-rejected rows dropped; and — when the kill switch is ON — unmatched or low/unknown-confidence
  **logo** rows dropped as noise, the "precision gate").
- `hashtagMatches`, `ambiguousHashtags` — from `content_hashtags`.
- `shipments` — `ShipmentEvidence[]` from `ShipmentEvidenceSource`.
- `paidPartnershipLabel` (`?bool`) — from `content_items.branded_content_label` (kill-switch ON) else `false`.
- `contextualCues` — gifting cues (kill-switch ON) else `[]`.
- `productDoctrine` (bool) — **= the kill switch** (§8). Selects the classification doctrine.
- `publishedAt` — the post's publication anchor (`published_at`; for stories, `captured_at`).

### `MentionClassifier::classify()` — exact order of evaluation
(`App\Platform\Enrichment\Attribution\MentionClassifier`)

1. **No signal at all** → return `null` (no mention created).
2. **`branded_content_label === true`** → `PAID` / `HIGH`. (QDS's own workflow never creates paid
   placements, so this only reflects the platform's own label.)
3. **Aligned shipments?** `alignedShipments()` keeps each gift record that both (a) *aligns* with
   independent relevance evidence and (b) is not *known* to be outside the timing window:
   - **Alignment** (`shipmentAligns`): a recognition whose `productId` equals the shipment's
     `productId` (product-level, primary), **or** a recognition brand-name equal to the shipment brand,
     **or** a targeted hashtag matching the shipment's `brand_id` / `campaign_id` / exact `product_label`.
   - If any shipment aligns, the outcome is **`SEEDED`**; confidence depends on the doctrine:
     - **`productDoctrine` ON:** `HIGH` only when a shipment aligns at the **product level**
       (`hasProductLevelAlignment`) **and** strong relevance **and** timing satisfied; otherwise
       `MEDIUM` and the signal `product-unconfirmed` is appended (→ human review, not auto-linked).
     - **`productDoctrine` OFF (legacy):** brand-level alignment is enough — `HIGH` when strong
       relevance + timing, else `MEDIUM`.
   - The proving records (`shipment-record:<id>`), plus `shipment-timing-unverified` /
     `product-unconfirmed` when applicable, plus cues, are recorded in the mention's `signals` list.
4. **No aligned shipment, only ambiguous hashtags** → `UNKNOWN` / `LOW` (`ambiguous-hashtag-match`) → review.
5. **No relevance at all** (only agency hashtags) → `UNKNOWN` / `LOW` (`no-seeding-record`) → review.
6. **Relevance but no gift link, strong** → `LIKELY_ORGANIC` / `MEDIUM`.
7. **Relevance but only weak** → `UNKNOWN` / `LOW` (`weak-signal`) → review.

"Strong" vs "weak" recognition = confidence `HIGH`/`MEDIUM` vs `LOW`/`UNKNOWN`
(`ConfidenceScore::toLevel`, cut-points `0.85` / `0.60`; text/OCR matches with no numeric score are
pinned `MEDIUM`).

---

## 5. The exact gates for `SEEDED`

A post is `SEEDED` (per tenant) only when **all** hold:

1. **A gift was shipped.** A `Shipment` for this creator with `shipped_at` set. Being on a seeding
   roster without a dispatched shipment is **not** enough (`ShipmentEvidenceSource` filters on
   `whereNotNull('shipped_at')`).
2. **Timing window.** The post's `publishedAt` is within `[anchor, anchor + windowDays]`, where
   `anchor = delivered_at ?? shipped_at` and `windowDays` is the tenant's *shipment window*
   (`qds.enrichment.attribution.shipment_window_days`, default **60**, overridable per tenant on
   Settings → Monitoring, ADR-0025). A post published *before* the anchor, or long after the window,
   does not align.
3. **Independent relevance.** The brand/product must actually appear (a recognition or a targeted
   hashtag). **A shipment alone never proves `SEEDED`.**
4. **Product vs brand (kill switch ON):** the *specific product* must be evidenced for `HIGH`;
   brand-only alignment caps at `MEDIUM` + `product-unconfirmed` → human review, never auto-linked.
5. **Campaign status is NOT checked.** `ShipmentEvidenceSource` returns every shipped shipment
   regardless of its `SeedingCampaignStatus` (Draft/Planned/Active/Shipping/Completed/Cancelled). A
   product that was shipped and whose campaign is later Completed **or Cancelled** still counts as
   seeding evidence if the post lands in the timing window. *(If you want Cancelled/Completed campaigns
   to stop counting, add a status filter to `ShipmentEvidenceSource` — it is a deliberate rule that
   does not exist today.)*

---

## 6. Product identity — the resolution ladder

`App\Platform\Enrichment\TextSignals\ProductResolver` maps a product tag or a caption phrase onto a
CRM `Product` (tenant-scoped), most-reliable-first:

`product_id` (if the source already has it) → exact **`sku`** → **`name`/`variant`** → **`products.aliases`** → (last resort) normalized text.

Guards: an **exact SKU** always counts; a **name/variant** match from *caption text* counts only when
the product's **brand is also present in the same post** (co-occurrence guard), so a generic name
("Lip Balm") shared across brands cannot create a false product hit. Matching is diacritic-folded but
**apostrophe-preserving** (so "Nike's" still whole-word-matches "Nike"; a brand written with an
apostrophe like "L'Oréal" matches its no-apostrophe form via a configured **alias**).

The resolved `product_id` is what lets the classifier reach product-level `HIGH` (`shipmentAligns`
compares `recognition.productId === shipment.productId`).

---

## 7. Per-tenant isolation (important)

Detection is fully tenant-isolated (ADR-0019 / ADR-0020):

- Every relevant table (`content_items`, `stories`, `platform_accounts`, `recognition_detections`,
  `mentions`, `content_hashtags`, `brands`, `products`, `shipments`, `seeding_campaigns`, …) carries
  `tenant_id`, is re-keyed so its natural/unique key **includes `tenant_id`**, and is joined with
  composite `(id, tenant_id)` foreign keys enforced in the database.
- The **same external post** monitored by two tenants becomes **two separate `content_items` rows**,
  each with its own detections and its own `Mention`.
- Enrichment runs under `TenantContext::runAs($contentItem->tenant_id)`, so `BrandLexicon`,
  `ProductResolver`, `ShipmentEvidenceSource`, and the settings resolver all read **only that tenant's**
  catalog and gift records.

**Consequence (worked example).** A creator posts product X. Tenant A shipped X to that creator
(in-window) → `SEEDED` for A. Tenant B has X in its catalog but never shipped it to this creator → X
can be *recognised* for B (if B monitors the creator) but can only be `LIKELY_ORGANIC` — it is **never
`SEEDED` for B**, because B has no proving shipment. It is not "counted for both."

---

## 8. The kill switch (rollout & rollback)

`QDS_ENRICHMENT_TEXT_SIGNALS_ENABLED` (config `qds.enrichment.text_signals.enabled`, **default `false`**)
gates the **entire** product-aware behaviour, so OFF is a true legacy no-op:

| | Switch **OFF** (default = legacy) | Switch **ON** (product-aware) |
|---|---|---|
| `TextSignalRecognizer` stage | skipped | runs (caption/mention/product-tag detections) |
| Logo precision gate | off (all logos count, as before) | on (drop unmatched/low-confidence logos) |
| Paid label | hardcoded `false` | real tri-state `branded_content_label` |
| Contextual cues | `[]` | detected |
| `EvidenceBundle.productDoctrine` | `false` | `true` |
| `recognition.productId` in evidence | forced `null` | carried |
| SEEDED doctrine | brand-only alignment → `HIGH` + auto-link (legacy) | product-level → `HIGH`; brand-only → `MEDIUM` + review, no auto-link |

Forcing `productId` to `null` when off is what makes the `shipmentAligns` product-ID shortcut inert in
legacy mode — so a rollback (turning the flag off with product-tagged detections already stored) truly
reproduces the old behaviour. All of this is in `AttributionService::buildEvidence` (`$enabled = config(...)`).

---

## 9. Confidence, human review, and auto-linking

- **Confidence envelope.** Every inferred value is wrapped in a `ConfidenceAssessment` (`value`,
  `confidenceLevel`, `signals`, `verificationStatus`). The **`signals` list is the explainability
  trail** — proving records, recognition breakdown, cues, and flags like `product-unconfirmed`.
- **Human precedence (DP-004).** Once a human reviews/corrects/confirms a detection or mention, no
  later AI run overwrites it (`HumanPrecedence::allowsAiUpdate`). AI-written rows start `AI_ASSESSED`.
- **Review routing.** `AI_ASSESSED` outcomes at `LOW`/`UNKNOWN` route to the review queue
  (`ConfidenceAssessment::needsHumanReview`). `HIGH`/`MEDIUM` auto-accept — **except** a `SEEDED`
  `MEDIUM` carrying `product-unconfirmed`, which is deliberately held for review.
- **Content ↔ shipment linking.** `App\Platform\Enrichment\Matching\SeededContentLinker` materialises
  `shipment_resulting_content` links from `SEEDED` mentions: it auto-links `AI_ASSESSED` `HIGH`/`MEDIUM`
  and any human-blessed mention, **but never a `product-unconfirmed` one**, and attributes the parent
  campaign only when the linked shipments resolve to exactly one campaign.

---

## 10. Data model touchpoints

| Table | Detection-relevant columns |
|---|---|
| `content_items` | `caption`, `media_urls`, `mentioned_handles`, `product_tags`, `collaborators`, `branded_content_label` |
| `recognition_detections` | `recognition_type` (LOGO / IMAGE_TEXT_OCR / ON_SCREEN_TEXT / SPOKEN_BRAND / CAPTION_TEXT / MENTION / PRODUCT_TAG / VISUAL_PRODUCT — DB CHECK constraint), `detected_brand`, `detected_product`, `product_id`, `provider_label` (immutable per-match key), `assessment`, `provenance` |
| `mentions` | `mention_type`, `classification` (confidence envelope + signals), `campaign_id` |
| `content_hashtags` | `normalized`, `matches`, `is_ambiguous`, `resolved_*` |
| `brands` | `name`, `aliases`, `social_handles` |
| `products` | `name`, `sku`, `variant`, `aliases` |
| `shipments` | `creator_id`, `product_id`, `shipped_at`, `delivered_at`, `seeding_campaign_id` |
| `shipment_resulting_content` | the materialised shipment ↔ content links |

`recognition_detections` upserts are idempotent on `(content_item_id|story_id, recognition_type, provider_label)`
where `provider_label` is a **stable per-match key** (`caption:<brand>`, `mention:<handle>`,
`product-tag:<tagId|productId>`, `caption-product:<productId>`) — so several brands/products from one
caption become distinct rows.

---

## 11. Configuration & operations

- `qds.enrichment.enabled` — master enrichment switch.
- `qds.enrichment.text_signals.enabled` — the product-aware kill switch (§8).
- `qds.enrichment.text_signals.gifting_cues` — DE/EN/FR cue phrase lists.
- `qds.enrichment.text_signals.short_brand_allowlist` — short brands allowed to match despite the ≥3-char noise guard (e.g. `dm`).
- `qds.enrichment.visual_match.enabled` — the visual product-matching kill switch (sub-project C, ADR-0029, default off); `qds.enrichment.visual_match.*` carries model version, frame budget, photo cap, and the E-calibrated placeholder thresholds.
- `qds.ai_budget.*` — capability-keyed AI spend governance (capability `embedding`); emergency stop `qds:ai-read-only`, per-tenant overrides `qds:ai-quota`.
- The plan-page "Visual product matching (embeddings)" row's `active` flag requires all three of the master enrichment switch (`qds.enrichment.enabled`), the visual-match kill switch above, and configured Google Embeddings service-account credentials to be true.
- `qds.enrichment.confidence.{high,medium}` — score→level cut-points (0.85 / 0.60, ADR-0026).
- `qds.enrichment.attribution.shipment_window_days` — default gift-link window (60), per-tenant via Settings → Monitoring (ADR-0025).
- `qds.matching.enabled` / `qds.matching.lookback_hours` — the `SeededContentLinker` sweep.

**Measuring quality.** `php artisan qds:eval-detection [--fixture=…]`
(`App\Platform\Enrichment\Console\EvalDetectionCommand`) scores the deterministic text path against a
labelled golden set (`tests/Fixtures/eval/golden-set.json`) and prints recall/precision. First recorded
baseline: **recall ≈ 0.71, precision ≈ 0.83** (10-case seed set). Extend the golden set to make the
baseline meaningful before gating future work on it. Cases may also carry a "visual" block (candidate
photo vectors + frame vectors) scored through the real BandMapper — product-level precision/recall,
false positives by category, band distribution, and estimated embedding cost per case.

---

## 12. Known limits & where this is going

Detection today is **brand/name-based**, not visual — a product is only found when a brand *name* is
legible/spoken/typed, a *known logo* is detected, or a *structured tag* names it. In particular:

- **Visual product recognition is closed-set embeddings only** — sub-project C (ADR-0029) matches
  keyframes against the tenant's *uploaded reference photos* for *candidate* products (in-window
  shipments + active roster). A product with no reference photos, or shown in a form the photos do
  not cover, is still missed; open-set **Gemini VLM** grounding is sub-project D
  (`needs_verification` on `visual_match_runs` is its pickup).
- **YouTube video files are not downloaded** (DEF-007) — YouTube's visual signal is the single
  Data-API thumbnail keyframe; TikTok and Instagram get real multi-frame coverage since
  sub-project B (ADR-0028), and every platform's frames feed §3f.
- **Speech is de-DE only, capped at ~60 s**; non-German or later-in-video spoken mentions are missed.
- **Confidence is bucketed provider scores**, not calibrated seeding probabilities.
- **Comments** are not used for seeding evidence.

Media resolution/keyframes (B, ADR-0028) and reference-photo embeddings (C, ADR-0029) have landed;
the forward plan (VLM grounding → confidence calibration) is tracked in
`docs/50-modules/seeded-product-detection-roadmap.md`. This document describes the **current**
behaviour; update it when D/E land.

---

## 13. Code map (where each piece lives)

| Concern | Class / file |
|---|---|
| Pipeline orchestration | `app/Platform/Enrichment/EnrichmentPipeline.php` |
| Structured-signal extraction | `app/Platform/Ingestion/Normalization/SignalExtract.php`; provider adapters under `app/Platform/Ingestion/Providers/` |
| Media recognition | `app/Platform/Enrichment/Recognition/RecognitionService.php`, `RecognitionNormalizer.php`, Google clients under `…/Http/` |
| Brand matching | `app/Platform/Enrichment/Recognition/BrandLexicon.php` |
| Text signals | `app/Platform/Enrichment/TextSignals/{TextSignalRecognizer,MentionExtractor,ContextualCueDetector,ProductResolver,ResolvedProduct}.php` |
| Hashtags | `app/Platform/Enrichment/Hashtags/{HashtagEnricher,HashtagExtractor,HashtagMatcher,HashtagNormalizer}.php` |
| Evidence assembly | `app/Platform/Enrichment/Attribution/AttributionService.php`, `EvidenceBundle.php` |
| Decision engine | `app/Platform/Enrichment/Attribution/MentionClassifier.php`, `ClassificationResult.php` |
| Gift records | `app/Modules/CRM/Services/ShipmentEvidenceSource.php`, `app/Platform/Enrichment/Attribution/ShipmentEvidence.php` |
| Confidence & precedence | `app/Platform/Enrichment/Support/ConfidenceScore.php`, `app/Shared/ValueObjects/ConfidenceAssessment.php`, `app/Platform/Enrichment/Support/HumanPrecedence.php` |
| Content ↔ shipment linking | `app/Platform/Enrichment/Matching/SeededContentLinker.php` |
| Quality scorecard | `app/Platform/Enrichment/Console/EvalDetectionCommand.php` |
| Visual product matching (C) | `app/Platform/Enrichment/VisualMatch/` — `VisualProductMatcher`, `CandidateScope`, `FrameProductScorer`, `BandMapper`, `VisualMatchWriter`, frame/photo embedders |
| AI budget governance | `app/Platform/AiBudget/` — `AiBudgetGuard`, `TenantQuotaResolver`, `qds:ai-read-only`, `qds:ai-quota` |

**Related docs:** `docs/50-modules/module-1-monitoring.md`, `docs/50-modules/module-3-crm-seeding.md`,
`ADR-0008` (attribution doctrine), `ADR-0019`/`ADR-0020` (tenancy), `ADR-0023` (per-pull enrichment),
`ADR-0025` (per-tenant settings), `ADR-0026` (confidence cut-points), `ADR-0028` (media/keyframes),
`ADR-0029` (visual matching), and the design spec noted at the top.
