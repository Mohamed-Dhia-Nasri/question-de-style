# Seeded-Product Detection — Modernization Roadmap (how to build the rest)

> **What this is.** The playbook for continuing the seeded-product detection modernization. It records
> the locked decisions, the status of each piece of work, and — in the appendix — a **ready-to-paste
> kickoff prompt** for each remaining piece so a fresh chat session can start it cold.
>
> Read [`seeded-product-detection.md`](seeded-product-detection.md) first to understand how detection
> works **today**. This document is about **what comes next and how to start it**.

---

## The vision

Move seeded-product detection from a brand/name matcher into a **visual, multimodal** system that
recognizes the actual gifted *product* on screen, in any language. The work is split into five pieces
that each **augment** the existing enrichment pipeline (they feed the same
`RecognitionDetection` → `EvidenceBundle` → `MentionClassifier` path — they do not replace it).

## Locked decisions (inherited by every piece — do NOT re-litigate these)

- **Augment, don't replace.** New capabilities write product-level evidence into the existing
  `recognition_detections` table (types `CAPTION_TEXT`/`MENTION`/`PRODUCT_TAG` today; visual/VLM
  evidence next). The classifier already consumes `productId`/`product`.
- **Tiered.** Cheap free signals run first; expensive AI (embeddings, VLM) runs only when needed
  (post is high-value / ambiguous / a cheap signal was inconclusive).
- **Vendor:** Google Cloud — **Gemini** (VLM) + **Google multimodal embeddings**; **EU data
  residency**. Vector index via **pgvector on Neon** (the app's existing Postgres).
- **Per-tenant isolation** (ADR-0019/0020): reference photos, embeddings, everything is tenant-scoped
  and enforced with composite `(id, tenant_id)` FKs.
- **Fail-closed, DP-004 human precedence, and a per-capability kill switch** (default off) — every new
  stage ships dark and is a true no-op until enabled, exactly like `QDS_ENRICHMENT_TEXT_SIGNALS_ENABLED`.
- **The eval scorecard is the measuring stick.** `php artisan qds:eval-detection` (grow its golden set)
  gates whether each new tier actually improves recall/precision.

## Status & dependency order

| Piece | Meaningful name | Status | Depends on |
|---|---|---|---|
| A | **Free-signal detection** (captions, @mentions, product tags, gifting cues, product-aware doctrine, eval scorecard) | ✅ DONE — merged to `main` (merge `55e96db`) | — |
| B | **Media resolution & keyframe sampling** (real media for TikTok/YouTube/long video; ffmpeg keyframes for all platforms) | ✅ DONE — merged to `main` (merge `9c9ef89`, ADR-0028) | — (independent of A) |
| C | **Visual product matching** (reference photos + Google multimodal embeddings in pgvector; match keyframes → seeded-SKU catalog) | ✅ DONE — built on `feat/seeded-detection-visual-match` (spec `docs/superpowers/specs/2026-07-19-visual-product-matching-design.md`, ADR-0029) | **B** |
| D | **VLM grounding & multilingual speech** (Gemini over keyframes+caption+transcript+tags → grounded product; ASR language-ID + >60s) | ✅ DONE — built on `feat/seeded-detection-vlm-grounding` (spec `docs/superpowers/specs/2026-07-20-vlm-grounding-design.md`, ADR-0030) | **B, C** |
| E | **Confidence calibration & eval expansion** (calibrate scores vs a labelled golden set; per-surface thresholds; multi-signal fusion; drift) | ⬜ Not started (eval-expansion part can begin any time on top of A) | A (feeds on B–D) |

**Critical path to "see the product on screen":** B ✅ → C ✅ → D ✅ — complete. Only E remains: its
calibration feeds on C's `visual_match_runs` history, D's `vlm_candidate_verdicts` (the "Gemini
agreement" fusion input), and the eval visual/vlm/speech metrics.

---

## How to start any piece (the process)

Each piece follows the **same superpowers cycle** that built A — A is the worked example, so copy its
shape:

1. **Open a fresh chat** in this repo (don't reuse a loaded session — each piece deserves clean context).
2. **Paste the kickoff prompt** from the appendix below. It carries the role, the locked decisions, the
   scope, and the open questions so the session doesn't re-derive them.
3. The session then runs, in order:
   - `superpowers:brainstorming` — asks you clarifying questions one at a time, resolves the open
     questions, and writes a **spec** to `docs/superpowers/specs/YYYY-MM-DD-<name>-design.md`.
   - `superpowers:writing-plans` — turns the spec into a **TDD task plan** in `docs/superpowers/plans/`.
   - `superpowers:subagent-driven-development` — builds it task-by-task (fresh implementer + reviewer
     per task, whole-branch review at the end).
   - `superpowers:finishing-a-development-branch` — merge / PR.
4. **Use a git worktree per piece** (`superpowers:using-git-worktrees`), or run pieces strictly one at a
   time. Two chat sessions in one working directory clobber each other via repo-wide `git checkout`
   (this happened during A's build). Separate worktrees = separate folders = safe parallelism.
5. **Rebase onto latest `main` first** (A landed after B branched; C/D/E must start from current `main`).

**The template to imitate:** the A spec (`docs/superpowers/specs/2026-07-18-seeded-detection-tier0-free-signals-design.md`)
and A plan (`docs/superpowers/plans/2026-07-18-seeded-detection-tier0.md`) show exactly the depth a
spec and a TDD plan should have. The A build history (16 tasks, each test-first, each reviewed) is the
quality bar.

## Where the insertion points already are (so C/D plug in cleanly)

- **Evidence:** write product-level hits as `RecognitionDetection` rows (a new `recognition_type`, e.g.
  `VISUAL_PRODUCT` for C and `VLM_PRODUCT` for D) carrying `product_id`/`detected_product`. They flow
  through `AttributionService::buildEvidence` → `MentionClassifier` with **no classifier changes needed**
  (product-level → HIGH SEEDED is already wired).
- **Frames:** C and D consume the keyframes B produces.
- **Gating:** add a per-capability kill switch alongside `text_signals.enabled` under
  `qds.enrichment.*`, default off.
- **Measurement:** every tier is judged by `qds:eval-detection` against the golden set.

---

## Appendix — ready-to-paste kickoff prompts

Open a **new chat in this repo**, paste the relevant block as the first message. (Confirm the working
tree is on a fresh branch off the latest `main`, ideally in its own worktree.)

### Sub-project C — Visual product matching (reference photos + embeddings)

```
Act as a Principal Staff Software Architect and AI/ML Platform Engineer (Laravel, Google Cloud AI,
vector search/pgvector, production ML). Priorities: correctness, maintainability, extensibility,
backward compatibility, explicit provenance, fail-closed, deterministic where possible, tenant isolation.

## Context
QuestionDeStyle (QDS) is a Laravel + PostgreSQL multi-tenant SaaS modernizing seeded-product detection.
Read docs/50-modules/seeded-product-detection.md (how detection works today) and
docs/50-modules/seeded-product-detection-roadmap.md (the programme + locked decisions) FIRST. Sub-project
A (free-signal detection) is merged to main; B (media resolution + keyframe sampling) provides real
media + ffmpeg keyframes for all platforms.

## Your task (this session): sub-project C — Visual product matching. Take it through
superpowers:brainstorming → a spec in docs/superpowers/specs/ → superpowers:writing-plans → a TDD plan.
Do NOT write product code until the spec and plan are approved.

## Scope
Give the detector "eyes" for the seeded PRODUCT (not just the brand): match sampled keyframes/images
against a per-tenant catalog of seeded-product REFERENCE PHOTOS using Google multimodal embeddings stored
in pgvector (Neon). A high-similarity match becomes a product-level RecognitionDetection (new type, e.g.
VISUAL_PRODUCT, carrying product_id) that flows through the existing evidence → classifier path
unchanged (product-level → HIGH SEEDED already wired). Depends on B's keyframes.

## Locked decisions (do not re-litigate): augment (feed the existing RecognitionDetection/EvidenceBundle/
MentionClassifier); Google multimodal embeddings + pgvector on Neon; EU data residency; tiered (embed
only sampled frames, and prefer the creator's plausible SKUs); per-tenant isolation; fail-closed;
DP-004 human precedence; a per-capability kill switch default OFF; the qds:eval-detection scorecard is
the success metric.

## Open questions to brainstorm (ask one at a time, recommend + confirm)
1. Where product reference photos come from + upload UX (brands/agencies upload 1–3 per SKU) — new
   product-image field/table, private disk, tenancy, and GDPR retention/erase (mirror story-media).
2. Embedding model specifics + how the pgvector index is structured per tenant (dims, index type,
   similarity metric, threshold), and how to keep it fresh as SKUs change.
3. Candidate scoping: match a frame only against the creator's in-window/active seeded SKUs, or the full
   tenant catalog with a guard? Cost/precision trade-off.
4. Similarity threshold → confidence mapping, and how a visual match becomes evidence (new
   recognition_type + provider_label identity + DP-004 upsert).
5. Cost controls + kill switch; batching; when to run (which posts, how many frames).

## Constraints: TDD (PHPUnit, base Tests\TestCase, RefreshDatabase, factories, XDEBUG_MODE=off); work on
a NEW branch off latest main (ideally a git worktree); commit spec + plan; NEVER add a Co-Authored-By /
AI-attribution trailer (a hook rejects it). Start by reading the two docs above, then begin brainstorming.
```

### Sub-project D — VLM grounding & multilingual speech (Gemini)

```
Act as a Principal Staff Software Architect and AI/ML Platform Engineer (Laravel, Google Cloud AI incl.
Gemini/Vertex, production ML). Same priorities as above.

## Context
Read docs/50-modules/seeded-product-detection.md and docs/50-modules/seeded-product-detection-roadmap.md
FIRST. A (free-signal detection) is on main; B (media/keyframes) and C (embedding-based visual product
match) are the prerequisites — D builds on both.

## Your task (this session): sub-project D — VLM grounding & multilingual speech. brainstorming → spec →
writing-plans → TDD plan. No product code until spec+plan approved.

## Scope
Add a Gemini (multimodal LLM) stage that, for high-value/ambiguous/borderline posts, ingests sampled
keyframes + caption + transcript + the candidate seeded-product list and returns STRUCTURED grounded
output {brand, product, visible?, spoken?, gifting_cue?, confidence, rationale}. Its product hits become
product-level RecognitionDetection rows (new type, e.g. VLM_PRODUCT) feeding the existing classifier
unchanged. Also fix speech: multilingual ASR (Google alternativeLanguageCodes / language-ID, enhanced
model, >60s via async/chunking) so EN/FR/DE and accented spoken mentions are caught.

Note: a shipped post can have NO `visual_match_runs` row at all (frameless extraction, DEF-021) — and even
when a row exists, a skipped run (budget/read-only/provider) never sets `needs_verification`. D's trigger
policy must treat BOTH as "unavailable", never as "verified absent" — absence of the flag is not evidence
of a clean look.

## Locked decisions (do not re-litigate): augment; Gemini + Google, EU residency; TIERED — the VLM is the
expensive last resort, gated to run only when cheaper tiers (A free signals, C embeddings) are
inconclusive OR the post is high-value (creator has an active shipment); per-tenant isolation;
fail-closed; DP-004; kill switch default OFF; qds:eval-detection is the metric. C's INCONCLUSIVE now also
covers a partial embed shortfall (some prepared frames transiently failed to embed) — treat that the same
as any other INCONCLUSIVE trigger, not as a clean NO_MATCH.

## Open questions to brainstorm (ask one at a time)
1. Exact VLM trigger policy (which posts, cost caps per tenant/day) and how it composes with C's cheaper
   embedding match.
2. The structured-output schema + prompt design (ground to the tenant's product catalog; force JSON;
   handle refusals/uncertainty; multilingual caption reasoning).
3. How VLM confidence + rationale map onto ConfidenceAssessment/signals (calibration, explainability,
   audit trail with frame timestamps).
4. Speech: alternativeLanguageCodes vs a multilingual model; async/long-audio handling; brand-name phrase
   hints; how the transcript path stays fail-closed.
5. Cost/latency (VLM is async + slow) — job design so it doesn't pin workers; kill switch; batching.

## Constraints: same as C (TDD, new branch/worktree off latest main, commit spec+plan, no
Co-Authored-By). Read the two docs, then brainstorm.
```

### Sub-project E — Confidence calibration & eval expansion

```
Act as a Principal Staff Software Architect and AI/ML Platform Engineer (Laravel, ML evaluation,
calibration). Same priorities as above.

## Context
Read docs/50-modules/seeded-product-detection.md and the roadmap FIRST. A is on main and already ships
qds:eval-detection (a golden-set scorecard, baseline recall ~0.71 / precision ~0.83). B/C/D have landed:
visual + VLM signals exist. D persists per-candidate VLM verdicts in `vlm_candidate_verdicts` — that
table IS the "Gemini agreement" fusion input — and D's band thresholds
(`qds.enrichment.vlm.thresholds`, auto 0.85 / review 0.60 / margin 0.10) plus C's
(`qds.enrichment.visual_match.thresholds`) are explicitly-placeholder values E calibrates.

## Your task (this session): sub-project E — Confidence calibration & eval expansion. brainstorming →
spec → writing-plans → TDD plan. No product code until approved.

## Scope
Make confidence trustworthy and measurable: (1) grow the labelled golden set and the qds:eval-detection
harness to report recall/precision at brand- AND product-level, per platform, per market; (2) replace the
raw provider-score bucketing (ConfidenceScore, 0.85/0.60) with calibrated scores against the golden set;
(3) multi-signal fusion (combine text/embedding/VLM evidence into one calibrated probability) and
per-surface / per-brand thresholds; (4) drift monitoring. The eval-harness part can start now; full
calibration needs C/D signals.

## Locked decisions (do not re-litigate): augment; per-tenant; fail-closed; DP-004; the golden set is the
ground truth; keep the confidence envelope (ConfidenceAssessment + signals) as the interface — calibration
changes how levels are DERIVED, not the envelope shape.

## Open questions to brainstorm (ask one at a time)
1. How the golden set grows (human corrections → labelled data via the review queue; per-tenant vs shared;
   fixtures vs a table) and how big before calibration is meaningful.
2. Calibration method (isotonic/Platt vs threshold tuning) and where it plugs in (ConfidenceScore or a new
   calibrator) without breaking the enum bucketing contract.
3. Multi-signal fusion model vs rule-based; per-surface (logo/OCR/caption/embedding/VLM) and per-brand
   thresholds — where configured (tenant settings?).
4. Drift/quality monitoring + how eval gates future changes (CI or a scheduled report).

## Constraints: same as C/D. Read the docs, then brainstorm.
```
