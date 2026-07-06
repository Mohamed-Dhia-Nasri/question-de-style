---
id: 40-data-source-matrix
title: Data Source Matrix
status: APPROVED
canonical_for:
  - "SRC-*"
  - capability-to-source-matrix
  - raw-to-domain-field-mapping
depends_on:
  - ADR-0001
  - ADR-0002
  - ADR-0003
  - DP-002
  - DEF-003
  - ../30-data-model/00-data-model.md
  - ../00-meta/03-glossary.md
  - ../05-decisions/decision-log.md
last_reviewed: 2026-07-03
---

# Data Source Matrix

This file is the **single source of truth for the closed external-provider registry** (`SRC-*`). It defines, for every platform and every data need, exactly which provider is called and what kind of data it yields. It also gives one short contract summary per provider and an overview of how raw provider fields populate domain entities.

> [!IMPORTANT]
> **Closed set — do not add providers.** The v1 provider stack is frozen. The only external providers that may be integrated are the `SRC-*` contracts listed in this file. Do not invent, substitute, or add providers. See [ADR-0001](../05-decisions/decision-log.md#adr-0001) (stack lock) and [DP-006](../20-cross-cutting/00-data-principles.md#dp-006).

> [!NOTE]
> **Every externally-sourced record carries a `Provenance` envelope** whose `source` field holds one of the `SRC-*` ids on this page. This is mandatory — see [DP-002](../20-cross-cutting/00-data-principles.md#dp-002). Entity field shapes (including the `Provenance` envelope) are defined only in [30-data-model/00-data-model.md](../30-data-model/00-data-model.md); this file references them, it does not restate them.

---

## 1. Provider tier legend

Each provider is tagged with a **provider tier** that describes the *nature of the provider*, not the metric tier of any individual value:

| Provider tier | Meaning |
| --- | --- |
| **PUBLIC** | Returns directly observable public data (public posts, public counts, public profiles). |
| **ESTIMATED** | Produces modeled / inferred values (none of the v1 providers are ESTIMATED-native; estimated reach is computed internally — see §4). |
| **AI** | A machine-learning inference service (vision / speech / video understanding) that enriches already-collected media. |

The **metric tier** carried by an individual stored value is a separate concept (`ENUM-MetricTier`: PUBLIC / DERIVED / ESTIMATED / CONFIRMED), defined in the [glossary](../00-meta/03-glossary.md#enum-metrictier). Note that derived rates (engagement rate, average and median performance) are tier **DERIVED** and estimated reach is tier **ESTIMATED** — these are computed internally, not returned by any provider (see §4).

---

## 2. Capability → source matrix

For each platform and each data need, the exact `SRC-*` provider and its provider tier. Every cell that is blank means **no provider exists for that capability on that platform in v1**; where a capability is deferred, the linked `DEF-*` explains why.

### 2.1 Platform data (collection)

| Data need | Instagram | TikTok | YouTube |
| --- | --- | --- | --- |
| Profile / channel metadata + public counts | `SRC-apify-instagram-profile-scraper` (PUBLIC) | `SRC-clockworks-tiktok-scraper` (PUBLIC) | `SRC-youtube-data-api-v3` (PUBLIC) |
| Posts (image posts / carousels) | `SRC-apify-instagram-post-scraper` or `SRC-apify-instagram-scraper` (PUBLIC) | `SRC-clockworks-tiktok-scraper` (PUBLIC) | — |
| Reels / short-form video + play/view counts | `SRC-apify-instagram-reel-scraper` (PUBLIC) | `SRC-clockworks-tiktok-scraper` (PUBLIC) | `SRC-youtube-data-api-v3` (PUBLIC) |
| Long-form / standard video + view counts | — | — | `SRC-youtube-data-api-v3` (PUBLIC) |
| Stories (before expiry) | `SRC-apify-instagram-story-details` (PUBLIC) | — | — |
| Comments (with timestamps, likes, replies) — **Deferred in v1** ([DEF-005](../20-cross-cutting/01-deferred-register.md#def-005)) | `SRC-apify-instagram-comment-scraper` (PUBLIC) | `SRC-clockworks-tiktok-scraper` (PUBLIC) | `SRC-youtube-data-api-v3` (PUBLIC) |
| Keyword / hashtag / topic search + discovery | `SRC-apify-instagram-scraper` (PUBLIC) | `SRC-clockworks-tiktok-scraper` (PUBLIC) | `SRC-youtube-data-api-v3` (PUBLIC) |
| In-reel transcript (optional add-on) | `SRC-apify-instagram-reel-scraper` (transcript add-on, PUBLIC) | — | — |
| Contact details (email / phone) | Deferred — [DEF-002](../20-cross-cutting/01-deferred-register.md#def-002) (profile scraper does **not** return email/phone; manual CRM entry only) | Deferred — [DEF-002](../20-cross-cutting/01-deferred-register.md#def-002) | Deferred — [DEF-002](../20-cross-cutting/01-deferred-register.md#def-002) |
| Audience demographics (country/age/gender) | Deferred — [DEF-001](../20-cross-cutting/01-deferred-register.md#def-001) | Deferred — [DEF-001](../20-cross-cutting/01-deferred-register.md#def-001) | Deferred — [DEF-001](../20-cross-cutting/01-deferred-register.md#def-001) |
| True unique reach / impressions (CONFIRMED) | Deferred — [DEF-003](../20-cross-cutting/01-deferred-register.md#def-003) | Deferred — [DEF-003](../20-cross-cutting/01-deferred-register.md#def-003) | Deferred — [DEF-003](../20-cross-cutting/01-deferred-register.md#def-003) |
| Authorized-creator analytics (OAuth Insights) | Deferred — [DEF-004](../20-cross-cutting/01-deferred-register.md#def-004) | Deferred — [DEF-004](../20-cross-cutting/01-deferred-register.md#def-004) | Deferred — [DEF-004](../20-cross-cutting/01-deferred-register.md#def-004) |

> [!NOTE]
> **TikTok is Apify-only.** `SRC-clockworks-tiktok-scraper` is the single TikTok provider for all TikTok needs (videos, profiles, comments, search). There is no usable official TikTok API for commercial monitoring/discovery in v1 — see [ADR-0002](../05-decisions/decision-log.md#adr-0002).

### 2.2 AI enrichment on collected media (cross-platform)

These `AI`-tier providers run on media already collected by the PUBLIC providers above. Each maps to a value of `ENUM-RecognitionType` (see [glossary](../00-meta/03-glossary.md#enum-recognitiontype)) and produces `ENT-RecognitionDetection` records carrying a `ConfidenceAssessment` (per [DP-003](../20-cross-cutting/00-data-principles.md#dp-003)).

| AI capability | RecognitionType | Provider | Provider tier | Applies to |
| --- | --- | --- | --- | --- |
| Image text OCR | `IMAGE_TEXT_OCR` | `SRC-google-cloud-vision` (TEXT_DETECTION) | AI | IG/TikTok/YouTube images & thumbnails |
| Logo detection in images | `LOGO` | `SRC-google-cloud-vision` (LOGO_DETECTION) | AI | IG/TikTok/YouTube images & thumbnails |
| Spoken-brand detection / audio transcript | `SPOKEN_BRAND` | `SRC-google-speech-to-text` (German models enabled) | AI | Any collected video/audio |
| Video-wide on-screen text + logo | `ON_SCREEN_TEXT` | `SRC-google-video-intelligence` (**optional**) | AI | Full video content (optional deep pass) |

### 2.3 Capability flow

```mermaid
flowchart LR
  subgraph Platforms
    IG[Instagram]
    TT[TikTok]
    YT[YouTube]
  end
  subgraph PUBLIC providers
    APF[Apify IG actors x6]
    CLK[SRC-clockworks-tiktok-scraper]
    YTAPI[SRC-youtube-data-api-v3]
  end
  subgraph AI providers
    VIS[SRC-google-cloud-vision]
    STT[SRC-google-speech-to-text]
    VID[SRC-google-video-intelligence optional]
  end
  IG --> APF
  TT --> CLK
  YT --> YTAPI
  APF --> MEDIA[(Collected content and media)]
  CLK --> MEDIA
  YTAPI --> MEDIA
  MEDIA --> VIS
  MEDIA --> STT
  MEDIA --> VID
```

---

## 3. Provider contract summaries (`SRC-*`)

<a id="src-apify-instagram-scraper"></a>
<a id="src-apify-instagram-reel-scraper"></a>
<a id="src-apify-instagram-profile-scraper"></a>
<a id="src-apify-instagram-post-scraper"></a>
<a id="src-apify-instagram-comment-scraper"></a>
<a id="src-apify-instagram-story-details"></a>
<a id="src-clockworks-tiktok-scraper"></a>
<a id="src-youtube-data-api-v3"></a>
<a id="src-google-cloud-vision"></a>
<a id="src-google-speech-to-text"></a>
<a id="src-google-video-intelligence"></a>

One short contract per provider: what it returns and its key limits. These are the **only** external providers permitted in v1.

### Instagram (Apify actors) — provider tier PUBLIC

- **`SRC-apify-instagram-scraper`** — Primary Instagram actor. Returns posts, reels, comments, and profiles, and supports hashtag + keyword search. Used as the general-purpose IG collector and IG discovery entry point.
- **`SRC-apify-instagram-reel-scraper`** — Returns reels including play/view counts. Offers an **optional transcript add-on** (in-reel spoken text).
- **`SRC-apify-instagram-profile-scraper`** — Returns profile metrics, bio, and links. **Limit: does NOT return email or phone** — contact details are manual-entry only ([DEF-002](../20-cross-cutting/01-deferred-register.md#def-002)).
- **`SRC-apify-instagram-post-scraper`** — Returns posts (image posts / carousels).
- **`SRC-apify-instagram-comment-scraper`** — Returns comments with timestamps, like counts, and threaded replies.
- **`SRC-apify-instagram-story-details`** — `louisdeconinck` actor. Returns stories. **Limit / feature: NO login required.** Used to archive stories before expiry.

### TikTok — provider tier PUBLIC

- **`SRC-clockworks-tiktok-scraper`** — The **only** TikTok provider. Returns views, likes, comments, shares, and saves; supports keyword search (videos + profiles); returns profiles and comments. **Limit: Apify-only; no official TikTok API is used** ([ADR-0002](../05-decisions/decision-log.md#adr-0002)). Subject to anti-bot fragility — see roadmap P4 data-quality monitoring.

### YouTube — provider tier PUBLIC

- **`SRC-youtube-data-api-v3`** — Official YouTube Data API v3. Supports video / channel / playlist search and returns public view, like, comment, and subscriber statistics. **Limit: public stats only**; authorized-creator analytics are deferred ([DEF-004](../20-cross-cutting/01-deferred-register.md#def-004)).

### AI enrichment (Google Cloud) — provider tier AI

- **`SRC-google-cloud-vision`** — Image OCR via `TEXT_DETECTION` and brand logo detection via `LOGO_DETECTION`.
- **`SRC-google-speech-to-text`** — Audio transcript / spoken-brand detection. **German models enabled** (DACH focus).
- **`SRC-google-video-intelligence`** — Video-wide on-screen text + logo detection. **Optional** deep-analysis pass over full video content.

---

## 4. Raw → domain field-mapping overview

This section maps each provider's raw output **groups** to the domain entities they populate. Entity field shapes are canonical in [30-data-model/00-data-model.md](../30-data-model/00-data-model.md) — the mapping below links there and **does not restate any entity's fields**.

Rules that apply to every row:

- Every record produced carries a `Provenance` envelope with `source` = the `SRC-*` id ([DP-002](../20-cross-cutting/00-data-principles.md#dp-002)).
- Public numeric counts (followers, views, likes, comments, shares, saves) are stored as `MetricValue` at metric tier **PUBLIC** ([ENUM-MetricTier](../00-meta/03-glossary.md#enum-metrictier)).
- Derived rates (engagement rate, average/median performance) are tier **DERIVED** and estimated reach is tier **ESTIMATED** — both are computed internally by the enrichment service, **not** returned by any provider (see the note below).
- Every inferred value (recognition hits, etc.) carries a `ConfidenceAssessment` ([DP-003](../20-cross-cutting/00-data-principles.md#dp-003)).

| Provider | Raw output group | Populates entity |
| --- | --- | --- |
| `SRC-apify-instagram-profile-scraper` | Profile identity, bio, links | [`ENT-Creator`](../30-data-model/00-data-model.md), [`ENT-PlatformAccount`](../30-data-model/00-data-model.md) |
| `SRC-apify-instagram-profile-scraper` | Public follower / following counts | [`ENT-PlatformAccount`](../30-data-model/00-data-model.md) (as `MetricValue`, tier PUBLIC) |
| `SRC-apify-instagram-post-scraper`, `SRC-apify-instagram-scraper` | Image posts / carousels + public counts | [`ENT-ContentItem`](../30-data-model/00-data-model.md) (`ContentType` IMAGE_POST / CAROUSEL) |
| `SRC-apify-instagram-reel-scraper` | Reels + play/view counts | [`ENT-ContentItem`](../30-data-model/00-data-model.md) (`ContentType` REEL) |
| `SRC-apify-instagram-reel-scraper` (transcript add-on) | In-reel transcript | [`ENT-RecognitionDetection`](../30-data-model/00-data-model.md) (`SPOKEN_BRAND` context) |
| `SRC-apify-instagram-story-details` | Story frames / metadata (pre-expiry) | [`ENT-Story`](../30-data-model/00-data-model.md) |
| `SRC-apify-instagram-comment-scraper` | Comments, timestamps, likes, replies | [`ENT-Comment`](../30-data-model/00-data-model.md) |
| `SRC-clockworks-tiktok-scraper` | TikTok profiles + public counts | [`ENT-Creator`](../30-data-model/00-data-model.md), [`ENT-PlatformAccount`](../30-data-model/00-data-model.md) |
| `SRC-clockworks-tiktok-scraper` | TikTok videos + views/likes/comments/shares/saves | [`ENT-ContentItem`](../30-data-model/00-data-model.md) (`ContentType` SHORT / VIDEO) |
| `SRC-clockworks-tiktok-scraper` | TikTok comments | [`ENT-Comment`](../30-data-model/00-data-model.md) |
| `SRC-youtube-data-api-v3` | Channel metadata + subscriber count | [`ENT-Creator`](../30-data-model/00-data-model.md), [`ENT-PlatformAccount`](../30-data-model/00-data-model.md) |
| `SRC-youtube-data-api-v3` | Videos + view/like/comment counts | [`ENT-ContentItem`](../30-data-model/00-data-model.md) (`ContentType` VIDEO / SHORT) |
| `SRC-youtube-data-api-v3` | Video comments | [`ENT-Comment`](../30-data-model/00-data-model.md) |
| `SRC-google-cloud-vision` | OCR text (`TEXT_DETECTION`), logos (`LOGO_DETECTION`) | [`ENT-RecognitionDetection`](../30-data-model/00-data-model.md) (`IMAGE_TEXT_OCR`, `LOGO`) |
| `SRC-google-speech-to-text` | Transcript / spoken brand mentions | [`ENT-RecognitionDetection`](../30-data-model/00-data-model.md) (`SPOKEN_BRAND`) |
| `SRC-google-video-intelligence` | Video-wide on-screen text + logos | [`ENT-RecognitionDetection`](../30-data-model/00-data-model.md) (`ON_SCREEN_TEXT`, `LOGO`) |

> [!NOTE]
> **`STORY` is not a `ContentType`.** Instagram stories map exclusively to `ENT-Story`, never to `ENT-ContentItem`. The `ContentType` enum and this rule are defined once in the [data model](../30-data-model/00-data-model.md) / [glossary](../00-meta/03-glossary.md#enum-contenttype).

> [!NOTE]
> **Write-ownership is not defined here.** Which module writes each entity above (e.g. `ENT-ContentItem`, `ENT-Story`, `ENT-Comment` are written by Module 1; `ENT-PlatformAccount` by Module 3) is canonical only in the [ownership matrix](../70-shared/00-ownership-matrix.md). This file describes *which provider fills which entity*, not *which module owns the write*.

---

## 5. Snapshots & historical note

> [!IMPORTANT]
> **Historical growth has no external source.** No provider returns time-series history. Historical performance is produced **internally** by recurring, timestamped `ENT-MetricSnapshot` records written by the snapshot scheduler service (`SVC-SnapshotScheduler`). Each snapshot re-captures current PUBLIC counts at a point in time; the series *is* the history. See [ADR-0003](../05-decisions/decision-log.md#adr-0003).

Consequently:

- A `MetricSnapshot` record still carries a `Provenance` envelope whose `source` names the PUBLIC provider that supplied the point-in-time counts (per [DP-002](../20-cross-cutting/00-data-principles.md#dp-002)); the *history* is the accumulation of snapshots, not a provider response.
- True unique reach / impressions remain **CONFIRMED**-tier and are deferred ([DEF-003](../20-cross-cutting/01-deferred-register.md#def-003)); v1 exposes PUBLIC views/plays plus clearly-labelled ESTIMATED reach (computed internally), never a provider-supplied reach figure.

---

## 6. Extension rule

The provider set on this page is closed. To change it, a new or amended [ADR](../05-decisions/decision-log.md) is required — do not add, swap, or invent providers in module or architecture docs. Modules and architecture reference this file for provider identity; they do not define their own providers.