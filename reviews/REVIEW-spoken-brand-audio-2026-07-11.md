# REVIEW TODO — Spoken-brand detection (SPOKEN_BRAND audio derivation pipeline)

Date: 2026-07-11. Scope: the uncommitted diff closing the last recognition gap — the audio derivation pipeline that lets SRC-google-speech-to-text run. Previously `RecognitionService` unconditionally reported `speech:no-audio-derivation-pipeline`; now video media gets a local-ffmpeg audio extraction and a real `speech.recognize` call. Also includes the per-service cost table on `/monitoring/plan` (2026-07-10) and its speech-row pricing.

Verification state at handoff: full suite **727 passing** (`XDEBUG_MODE=off php artisan test`), PHPStan clean except the 12 pre-existing `DemoDataSeeder` errors, Pint clean. New tests: `tests/Feature/Enrichment/AudioExtractorTest.php` (real-ffmpeg integration, self-skipping when ffmpeg absent) + 6 speech-path cases in `RecognitionPipelineTest`; `speech:no-audio-derivation-pipeline` assertion in `EnrichmentPipelineTest` updated to `speech:not-configured`.

> **STANDING TODO: fold this diff into the deferred adversarial review queue** (alongside Module 3 whole-diff, ADR-0017 cost-optimization, and P4 hardening — all still pending).

## What was built (file map)

- `app/Platform/Enrichment/Recognition/AudioExtractor.php` — **new**. ffmpeg shell-out: video bytes → temp file → `-vn -ac 1 -ar 16000 -t ≤60 -f flac` → FLAC bytes. Fixed argv (never a shell string), `-nostdin`, 60s process timeout, temp files unlinked in `finally`, 7 MB output cap (10 MB sync-request limit after base64). Every failure → `null`, never fabricated. `isAvailable()` memoized per instance via `ffmpeg -version`.
- `config/qds.php` — `qds.enrichment.audio.{ffmpeg_path, max_seconds}` (`QDS_ENRICHMENT_FFMPEG_PATH`, `QDS_ENRICHMENT_AUDIO_MAX_SECONDS`); max_seconds clamped to 60 in code.
- `app/Platform/Enrichment/Recognition/RecognitionService.php` — injects `GoogleSpeechClient` + `AudioExtractor`; speech stage runs after video-intelligence for video media with three independent gates/skip markers: `speech:not-configured`, `speech:ffmpeg-unavailable`, `speech:audio-extraction-failed`; no-provider early return now includes speech.
- `app/Platform/Ingestion/Support/IngestionCostEstimator.php` — speech row in `perService()` priced at $0.024/audio-minute over the sweep-capped video volume, key-gated like the Vision/Video rows.

## Review checklist

### AudioExtractor safety & correctness
- [ ] Untrusted scraped bytes hit ffmpeg — is the surface adequately constrained (argv-only, -nostdin, timeout)? Should a dedicated sandbox/user or ffmpeg `-max_alloc`-style limits be considered?
- [ ] Temp-file handling: race/perm issues with `tempnam` pairs; leftover files if the PHP process is hard-killed mid-run.
- [ ] `maxSeconds()` clamp interacts correctly with config 0/negative values.
- [ ] 7 MB cap: is truncation-by-rejection (null) the right call vs. re-encoding at a lower bitrate?
- [ ] Memoized `isAvailable()` — stale `false` within a long-running worker after ffmpeg is installed? (Per-instance memo bounds this to one job.)

### RecognitionService wiring
- [ ] Speech failure semantics: a `ProviderCallException` from `speech.recognize` propagates like Vision's (fails the run) — intended parity?
- [ ] Duplicate-detection guard (DP-004 human precedence) holds for SPOKEN_BRAND rows exactly as for OCR/LOGO.
- [ ] Stories with video media go through the same path (`mediaFor` returns video bytes for `looksLikeVideo`) — verified by test only for ContentItems.
- [ ] Cost: speech now bills per video enrichment — sweep_batch cap is the only brake; is a separate speech kill-switch warranted?

### Estimator / plan page
- [ ] $0.024/min list price and the reused `videoMinutes` assumption (4 min/account/mo, sweep-capped) — sane?
- [ ] Note strings match the real gating (enrichment flag vs key vs ffmpeg — ffmpeg presence is NOT reflected in the row's active flag).

### Docs/governance
- [ ] Data-source matrix §2.2 says SPOKEN_BRAND "requires audio derivation not yet canonically specified" — this diff specifies it operationally; needs a doc amendment/ADR (add to the missing-decision list with sentiment model et al.).
- [ ] DP-005 review: derived audio (not raw video) leaves the platform to Google — confirm this reading is canon-compatible.


---

## Verified adversarial review — RUN 2026-07-13

Multi-agent workflow (3 lenses → 3-skeptic majority-confirm). **8 confirmed**, none high-severity. Safety-critical invariants hold: idempotent persistence under retry (no data loss), correct temp-file cleanup on all in-process paths, stack-lock/DP-005 intact.

**Verdict:** solid diff. The two behavioral mediums are FIXED with tests; cost-display and defense-in-depth items are DEFERRED.

### FIXED (this session, with tests; full suite 874 green)
- **[MED] Transient Speech failure fails the whole run and re-bills Vision + Video-Intelligence on every retry.** `RecognitionService` now catches `ProviderCallException` from `speech.recognize`, records it, emits a `speech:provider-error` skip marker, and lets the run complete — SPOKEN_BRAND just stays unavailable. Test: `RecognitionPipelineTest::test_transient_speech_failure_degrades_gracefully_without_failing_the_run`.
- **[MED→partial] Video story with a non-mp4/webm Content-Type archived as `.bin` and misrouted to Vision, silently skipping speech.** `ArchiveStoryMediaJob::extensionFor()` now maps any `video/*` subtype (e.g. `video/quicktime`) to a video extension so it routes to the speech path. (The genuinely ambiguous `null`/`application/octet-stream` case still needs a magic-byte sniff at archive time — DEFERRED.)
- **[LOW] Input temp file leaked when the second `tempnam()` returns false** → the survivor is now unlinked on the early-return path.

### DEFERRED (recorded)
- **[MED→partial] `.bin` / octet-stream video story routing** — needs a magic-byte MIME sniff (or a persisted resolved media-kind) at archive time to fully close; add a Story (not just ContentItem) speech-path test.
- **[LOW] Speech cost row shows active + nonzero when ffmpeg is absent** (spend that can't occur). Gate the active flag on `AudioExtractor::isAvailable()` or note the ffmpeg prerequisite in the active-branch string.
- **[LOW] Speech monthly estimate reuses full `videoMinutes`** but bills ≤60s/video (≤1 min cap) → up to 2× overstatement. Base the volume on video-count-capped-at-1-minute or relabel.
- **[LOW] ffmpeg on untrusted media has a time bound but no memory/allocation ceiling** (OOM availability risk on a shared worker). Add `-max_alloc`/ulimit/cgroup or a low-priv user. Env-dependent hardening.
- **[INFO] Temp files leak on hard-kill (SIGKILL/OOM)** — `finally` can't run. Add a periodic `qds-audio-*` sweep (e.g. in the data-quality monitor).
- **[INFO] Local ffmpeg audio-derivation step is undocumented in canon** — add to data-source-matrix §2.2 / an ADR (≤60s FLAC, raw-vs-derived, ffmpeg as a hard prerequisite; DP-005/DP-006 confirmed compatible).

### Invariants CLEARED
Idempotent DP-004 upsert (no dup/loss on retry incl. the re-bill scenario); temp cleanup correct on normal return, caught throwable, and `Process::timeout`; per-call temp isolation (no cross-tenant leak); DP-006 stack-lock intact (ffmpeg local) and DP-005 (only derived audio leaves); `-vn` sheds the video decode-bomb surface; the speech estimate never under-bills; full-run-failure is cost/telemetry amplification only (bounded by tries=4 + sweep cap).

### Coverage gaps (need live runs)
ffmpeg OOM triggerability needs a live fuzz/PoC; whether story reels arrive as null/octet-stream/quicktime needs live CDN observation; real Google Speech 429 frequency governs the re-bill cost; host tmp-reaping is operator-dependent; average video duration (estimate magnitude) needs live telemetry; the doc amendment is an author action.
