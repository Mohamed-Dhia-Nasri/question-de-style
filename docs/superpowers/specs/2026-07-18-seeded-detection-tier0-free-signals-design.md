# Sub-project A — Tier 0 "Free-Signal Foundation" for Seeded-Product Detection

- **Date:** 2026-07-18
- **Status:** Design — awaiting user review before implementation planning
- **Author:** brainstorming session (Claude + user)
- **Part of:** the "modern (VLM + embeddings) seeded-product detection" programme (sub-projects A–E). This spec covers **A only**.

---

## 1. Context & motivation

A whole-pipeline audit (43-agent adversarially-verified review, 2026-07-18) found the current seeded-product detection is a **brand-mention detector, not a product detector**. Its recognition stack is Google Vision (`LOGO_DETECTION` + `TEXT_DETECTION`), Video Intelligence (`LOGO_RECOGNITION` + `TEXT_DETECTION`) and Speech-to-Text (de-DE), whose only downstream use is an **exact, whole-word, first-match-only** dictionary lookup of CRM brand names. The two confirmed **Critical** findings:

1. **No visual product recognition** — a detection only exists if a brand *name* is legible/spoken or a known *logo* appears; the "product" in "seeded-product" is architecturally absent.
2. **Captions and `@mentions` produce zero brand signal** — the caption is mined only for `#hashtags` against a curated list; brand names in caption prose, `@mentions`, gifting cues, product tags, collaborator tags, and the paid-partnership label are all discarded (`paidPartnershipLabel` is hardcoded `false`).

The agreed target architecture is a **three-tier, augment-not-replace** approach that feeds the existing evidence/attribution/review/tenancy core:

- **Tier 0 (this sub-project A):** free, no-AI-cost signals — structured platform tags + caption text mining + decision-core hardening + an eval harness.
- Tier 1 (C): Google multimodal embeddings vs a per-tenant seeded-product reference-photo index (pgvector).
- Tier 2 (D): Gemini VLM grounding + multilingual speech.
- Media resolution + keyframe sampling (B) unblocks C/D on TikTok/YouTube/long video.
- Confidence calibration (E) is cross-cutting.

**A ships first** because it needs no new AI vendor, closes the largest recall gap and the worst precision holes immediately, and produces the scorecard (A5) that measures every later tier.

## 2. Agreed decisions (from brainstorming)

| Decision | Choice |
|---|---|
| Relationship to existing pipeline | **Augment** — reuse `EvidenceBundle` → `MentionClassifier` → `Mention` → review → `SeededContentLinker` |
| Where the new text/tag signals are stored | **Reuse `RecognitionDetection`** + **add a `detected_product` column** (make the table product-aware) |
| Human-in-the-loop | **Keep** a light review queue (auto-accept confident results; only uncertain cases go to a person). Later tiers shrink it |
| Vendor (for later tiers) | Gemini + Google multimodal embeddings, stay in Google Cloud (EU residency) — **not used in A** |
| Product identity in A | From **(1) structured product tags** and **(2) product name/SKU matched in caption text**. Visual product identity is C/D |

## 3. Goal & success criteria

**Goal:** detect seeded **products** (not just brands) from the free signals already present in every pull, in EN/FR/DE, and stop the known false-SEEDED alarms — without any new AI spend.

**Success criteria:**
- The canonical case *"thanks @glossier for the PR 🎁"* with a matching in-window Glossier shipment is classified **SEEDED**, and where the product is named (tag or caption text) the **exact product** is attributed.
- Brand-only alignment no longer auto-confirms HIGH SEEDED; it routes to review.
- The PAID branch becomes reachable (real paid-partnership label wired in).
- A repeatable `qds:eval-detection` scorecard reports recall + precision at brand and product level, per platform, giving a measured baseline.

## 4. Scope

**In scope:** structured-signal ingestion (A2); caption brand + product + `@mention` + gifting-cue mining and the product-aware `RecognitionDetection` (A3); decision-core changes for product-level alignment, brand-only→review, paid-label wiring, precision gates (A4); eval harness (A5).

**Out of scope (later sub-projects):** downloading/keyframe-sampling media (B); reference photos + embeddings + visual product match (C); Gemini VLM + multilingual speech (D); confidence calibration model (E); edit-distance fuzzy matching for OCR/ASR error recovery (belongs with C/D's media paths — A does diacritic-folding + multi-match only, which covers typed captions).

## 5. Design

### A2 — Structured-signal ingestion

**Intent:** stop discarding the tags the platforms already hand us.

- **`ContentData` DTO** (`app/Platform/Ingestion/DTO/ContentData.php`) — add four optional fields, all defaulting empty (never fabricated, DP-002 provenance unchanged):
  - `mentions: list<string>` — `@handles` referenced by the post.
  - `productTags: list<ProductTag>` — a new small value object `ProductTag { brandRef: ?string, productName: ?string, productSku: ?string, providerTagId: ?string }` (the exact tagged product; `providerTagId` is the platform's own tag identifier when present).
  - `collaborators: list<string>` — brand/creator co-author handles.
  - `brandedContentLabel: ?bool` — the real paid-partnership disclosure, **tri-state**: `true` = confirmed paid/branded-content, `false` = provider explicitly says not paid, `null` = provider did not supply it. Never conflate `null` with `false`.
- **Adapters** — populate the fields where the provider payload exposes them; leave empty otherwise:
  - `InstagramPostAdapter`, `InstagramReelAdapter` — `taggedUsers`/`mentions`, `coauthorProducers`, product tags, sponsor/paid flag.
  - `InstagramStoryAdapter` → `StoryData` — mention stickers / product stickers where present.
  - `TikTokContentAdapter`, `YouTubeContentAdapter` — mentions from text; product/links where available.
  - **Exact provider field names are confirmed against stored `ProviderResponseSample` rows during implementation** (the adapters already quarantine schema drift loudly — reuse that path).
- **Persistence** — add columns to `content_items` (mirroring the `media_urls`/`public_metrics` JSONB pattern) and the analogous story fields:
  - `mentions jsonb null`, `product_tags jsonb null`, `collaborators jsonb null`, `branded_content_label boolean null` (**nullable** tri-state — no default).
  - Update `ContentItem` (`fillable` + `casts`) and `ContentItemPersister`; `StoryPersister`/`Story` for the story equivalents.

### A3 — Caption/tag mining into a product-aware `RecognitionDetection`

**Intent:** read the caption and the tags, and write down both the **brand** and the **product**.

- **`RecognitionDetection`** (`app/Modules/Monitoring/Models/RecognitionDetection.php` + migration) — add:
  - `detected_product varchar null` and `product_id` (nullable FK to `products`, tenant-scoped) — the product column that makes the table product-aware. `detected_product` is human-correctable like `detected_brand` (kept out of the upsert identity so a correction is not re-created).
- **`RecognitionType` enum** (`app/Shared/Enums/RecognitionType.php`) — add three **source** types (product-ness is expressed by whether `detected_product` is set, not by the type):
  - `CaptionText = 'CAPTION_TEXT'` — brand and/or product matched in caption prose.
  - `Mention = 'MENTION'` — brand resolved from an `@handle`.
  - `ProductTag = 'PRODUCT_TAG'` — structured tag → brand **and** exact product.
- **New stage `TextSignalRecognizer`** (`app/Platform/Enrichment/Recognition/…` or a sibling `TextSignals/` namespace) wired into `EnrichmentPipeline::run` **after** recognition and **before** attribution. For a `ContentItem` (stories: only the sticker-derived tags, since ENT-Story has no caption) it produces `RecognitionDetection` rows via the **existing idempotent upsert + human-precedence** path (`RecognitionService::persist` pattern — factor the shared upsert so both callers use it):
  1. **Caption brands** → run caption text through the upgraded `BrandLexicon::matchAllInText` → one `CaptionText` detection per distinct brand.
  2. **Caption products** → match caption text against the **full tenant product catalog** (not only active-shipment products) via the **product-resolution ladder** below → sets `product_id`/`detected_product` on a product detection. **Precision guard:** an **exact SKU** match always counts; a **name/variant** match counts only when the product's **brand also appears in the same post** (co-occurrence), so a generic name ("Lip Balm", "Serum") shared across brands cannot create a false product hit. Whether a product match becomes SEEDED vs organic is decided later in A4 by shipment alignment — matching is not scoped to shipments here.
  3. **`@mention` brands** → new `MentionExtractor` (sibling of `HashtagExtractor`, pattern `/@([A-Za-z0-9._]+)/`) → resolve each handle against a new **`brands.social_handles`** field → `Mention` detection.
  4. **Product tags** → resolve each ingested `ProductTag` to a CRM brand + product through the **product-resolution ladder** → `ProductTag` detection carrying the **exact product** and its `product_id` (near-ground-truth).

  **Product-resolution ladder** (text/tag → `product_id`, most reliable first): **(1) `product_id`** if the source already carries it → **(2) exact `sku`** → **(3) `name`/`variant`** (diacritic-folded, brand-corroborated) → **(4) `products.aliases`** (new column, sibling of `brands.aliases`) → **(5) normalized text** as a last resort. A confident rung sets `product_id`; a weak (rung 5) match stores `detected_product` text with no `product_id` (lower confidence). Name equality is never the primary key.

  **Detection identity (idempotency):** for the new source types `provider_label` carries the **stable per-match key** — the normalized matched brand/product value, or the provider's tag ID for product tags — so multiple brands/products from one caption become distinct rows instead of colliding. `CaptionText` → `caption:{normBrand}` / `caption-product:{product_id|normName}`; `Mention` → `mention:{normHandle}`; `ProductTag` → `product-tag:{providerTagId|product_id|normName}`. The text **offset** is stored as an attribute for explainability but is **not** part of the key (a caption edit shifts offsets and would break re-pull idempotency).
  5. **Gifting cues** → a `ContextualCueDetector` scans the caption for a curated multilingual phrase list (DE/EN/FR: "PR-Paket / unbezahlt / gratis", "gifted / PR / c/o / thanks to", "offert / cadeau / collab") → emitted as `EvidenceBundle.contextualCues` (a relevance booster, **not** a brand claim, so it is not a `RecognitionDetection`).
- **`BrandLexicon` upgrade** (`app/Platform/Enrichment/Recognition/BrandLexicon.php`):
  - `matchAllInText(string): list<string>` — return **all** distinct brands found (dedup, keep first offset for explainability). `matchInText` kept as a thin first-match wrapper for existing callers.
  - **Diacritic folding** — normalize both haystack and needles (Unicode NFKD + strip combining marks) so `L'Oréal`, `LOreal`, `loreal` match. This is the accent tolerance for typed text.
  - **`@handle` resolution** via `brands.social_handles` (new `jsonb null` column on `brands`, sibling of `aliases`).
  - Replace the blanket `MIN_TEXT_MATCH_LENGTH = 3` floor with a **curated short-brand allowlist** (so `dm`, `so` etc. match when explicitly listed, without re-opening 1–2 char noise for everything).
- **Config** (`config/qds.php` enrichment block) — add: the multilingual gifting-cue phrase lists, the short-brand allowlist, and a `text_signals.enabled` toggle (kill switch, mirroring `enrichment.enabled`).

### A4 — Product-aware, trustworthy decision core

**Intent:** the exact gifted **product** → confident SEEDED; **brand only** → review; and finally separate paid from gifted.

- **`EvidenceBundle`** (`app/Platform/Enrichment/Attribution/EvidenceBundle.php`) — extend:
  - `recognitions` entries carry `productId` + `product` alongside `brand` and `level` (from the product-aware detections).
  - add `contextualCues: list<string>`.
  - `paidPartnershipLabel: ?bool` is populated from the ingested `branded_content_label` in `AttributionService::buildEvidence` (**retire the hardcoded `false`** at line 237). Only `true` fires the PAID branch; `null`/`false` do not.
- **`ShipmentEvidence`** (`app/Platform/Enrichment/Attribution/ShipmentEvidence.php`) — add `productId: ?int` (from `shipment->product_id`) alongside the existing `productLabel`, so alignment can key on the ID.
- **`MentionClassifier`** (`app/Platform/Enrichment/Attribution/MentionClassifier.php`) — `shipmentAligns`/`shipmentHasStrongRelevance` gain a **product-level branch**, matching on the **product-resolution ladder** (ID first):
  - **Product match** — `recognition.productId === shipment.productId` (primary); fall back to exact SKU, then diacritic-folded name/variant, only if an ID is unavailable → **product-level alignment** → eligible for **HIGH** SEEDED (with timing satisfied).
  - **Brand-only match** (no product identity) → capped at **MEDIUM** and flagged `product-unconfirmed` (a review signal) — it does **not** auto-confirm and is **not** auto-linked. `SeededContentLinker::linkable()` excludes `product-unconfirmed` mentions; the review queue predicate is extended to surface them. This directly fixes the "old Nike hoodie = SEEDED" false positive.
  - **Contextual cue + brand relevance** strengthens confidence and is recorded as a signal (explainability); a **cue alone** (no brand/product) stays weak → review.
  - **Real paid label** makes the PAID branch reachable, so paid placements are correctly separated from organic seeding.
- **Precision gates** (small, high-value, decision-core):
  - Do not treat an **unmatched** logo (brand not in the CRM lexicon) or a **very-low-score** logo as brand relevance for attribution (informational only).
  - Do not treat **scoreless caption/OCR** brand text as "strong" on its own for HIGH — require corroboration (a second signal or a product match).

### A5 — Eval harness (the scorecard)

**Intent:** measure, don't guess.

- A **labelled golden set** of ~60–100 real posts across IG / TikTok / YouTube, hand-labelled with: `is_seeded`, `brand`, `product` (nullable), `platform`, and the reason. Stored as fixtures (and/or a small `detection_eval_cases` table, tenant-scoped) — see Open Questions.
- A console command **`qds:eval-detection`** (`app/Platform/Enrichment/Console/…`) that runs the full enrichment/classification over the set and prints a **confusion matrix + recall + precision**, broken down by **brand-level vs product-level** and by **platform**.
- Produces the **baseline number today** and is the regression/uplift gate for B/C/D.

## 6. Data-model changes (summary)

| Table | Change |
|---|---|
| `content_items` | + `mentions` jsonb, `product_tags` jsonb, `collaborators` jsonb, `branded_content_label` **bool null (tri-state)** |
| `stories` | + analogous mention/product-sticker fields (where the story provider exposes them) |
| `recognition_detections` | + `detected_product` varchar null, `product_id` FK null (tenant-scoped) |
| `brands` | + `social_handles` jsonb null |
| `products` | + `aliases` jsonb null (sibling of `brands.aliases`; last-but-one rung of the resolution ladder) |
| (new, optional) `detection_eval_cases` | labelled golden set for A5 (or fixtures) |

`ShipmentEvidence` VO gains `productId: ?int` (no table change — sourced from `shipment->product_id`).

New enum values: `RecognitionType::{CaptionText, Mention, ProductTag}`. New VO: `ProductTag`. New config: gifting cues, short-brand allowlist, `text_signals.enabled`.

## 7. Data flow (with the running example)

```
Pull post "thanks @glossier for the PR 🎁"  (+ maybe an IG product tag → Glossier You Perfume)
  A2  adapter captures: mentions=[@glossier], productTags=[{Glossier, You Perfume}], brandedContentLabel=false
      persisted on content_items
  A3  TextSignalRecognizer writes RecognitionDetections:
        CaptionText  brand=Glossier                        (from "@glossier"/caption prose)
        Mention      brand=Glossier                        (from @glossier handle)
        ProductTag   brand=Glossier  product=You Perfume    ← exact product (if tagged)
        [or] CaptionText product=You Perfume                ← if the caption *names* the product
      contextualCues=["gifting-cue:PR"]
  A4  buildEvidence → MentionClassifier:
        product "You Perfume" == shipment.productLabel "You Perfume", in 60-day window
        → SEEDED, HIGH, product identified, signals include shipment-record + product-match + gifting-cue
      (if the product were NOT named anywhere → brand-only → SEEDED MEDIUM, product-unconfirmed → review)
```

## 8. Invariants & error handling

- **DP-004 human precedence** preserved on every new `RecognitionDetection` and `Mention`; ambiguous `@mention`/caption matches route to review exactly like ambiguous hashtags.
- **Graceful/never-fabricated** — a missing provider field → empty; a missing signal → no row. No made-up data.
- **Idempotent upserts** keyed on stable identity (`content_item_id`/`story_id` + `recognition_type` + `provider_label`), reusing the existing partial unique index + `UniqueConstraintViolationException` handling. For the new source types `provider_label` holds the **per-match key** (normalized matched value / provider tag id — see A3), so multiple brands/products from one caption stay distinct rows; the text offset is a stored attribute, never part of the key.
- **Tenant-scoped** — all new tables/columns via `BelongsToTenant`; `product_id`/handle resolution is tenant-scoped; cross-tenant references resolve to null.
- **Kill switch** — `text_signals.enabled` gates the new stage; no behavioural change when off.
- **No new provider cost** — A is entirely local text/tag processing.

## 9. Testing strategy

- **Unit** — `MentionExtractor` (handles, dedup), `ContextualCueDetector` (DE/EN/FR phrases, negatives), `BrandLexicon` (diacritic fold, `matchAllInText`, `@handle` resolution, short-brand allowlist), `ProductTag` resolution.
- **Adapter** — each adapter maps the new fields from representative stored `ProviderResponseSample` payloads; absent fields → empty.
- **Classifier** — product-match → HIGH; brand-only → MEDIUM + `product-unconfirmed` + not auto-linked; cue-alone → weak/review; real paid label → PAID; precision gates (unmatched/low-score logo, scoreless OCR).
- **Integration** — end-to-end enrichment of the canonical "@glossier PR" post → SEEDED with the product named; and the brand-only variant → review.
- **Eval** — `qds:eval-detection` runs green on the golden set and prints the baseline.
- Run with `XDEBUG_MODE=off` (repo convention).

## 10. How A sets up B–E

- The **product-aware `RecognitionDetection`** and the extended `EvidenceBundle`/`MentionClassifier` are the exact insertion points where C (embedding product matches) and D (VLM product grounding) will write their product-level evidence — no further classifier surgery needed.
- The **eval harness (A5)** is the measurement gate that proves B/C/D actually raise recall/precision.
- The **brand-only → review** rule is the precise trigger the visual tiers will later intercept ("only the brand matched + a shipment exists" → look at the frames first, human only if still unsure).

## 11. Open questions / risks

1. **Golden set storage** — fixtures vs a `detection_eval_cases` table. Recommendation: start with fixtures (no schema, easy to version); promote to a table if the set grows or needs per-tenant labels.
2. **Exact provider field names** — Instagram/TikTok product-tag, collaborator, and paid-partnership field names must be confirmed against real `ProviderResponseSample` payloads during implementation; some may be unavailable on the current actors (then that signal is simply empty until B's richer scrape).
3. **`brands.social_handles` population** — needs a CRM entry point (brands add their `@handle`); until populated, `@mention` resolution is empty for that brand (graceful). A small CRM field addition, possibly folded into A or a fast-follow.
4. **Short-brand allowlist curation** — operational config; must be seeded with the real DACH short brands (e.g. `dm`).
5. **`products.aliases` population** — new column for the resolution ladder; needs a CRM entry point and initial curation. Until populated, the ladder simply skips that rung (graceful). Fold into A or fast-follow.
6. **Generic-name co-occurrence guard** — the name/variant rung requires the brand in the same post. Confirm this is strict enough for your catalog (some SKUs are distinctive enough to match on name alone — could be a per-product "distinctive" flag later, out of scope for A).
7. **Story caption absence** — stories still have no caption, so story text-mining is limited to sticker-derived tags; full story coverage depends on B/C/D visual tiers.
