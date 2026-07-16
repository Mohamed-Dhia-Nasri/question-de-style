# EMV & Reach settings — non-technical simplification — design spec

- **Date:** 2026-07-16
- **Status:** Approved (user-directed), implementing
- **Related canon:** REQ-M1-006 (estimated reach), REQ-M1-011 (EMV config), MET-EMV (Σ metric × rate), GL-PublicViews / DEF-003 (reach is never a raw view count), ADR-0022 (reach method), 2026-07-13 reach settings spec.

## 1. Problem

`/settings/emv` and `/settings/reach` expose the admin data model directly: raw JSON
textareas ("Per-platform overrides (JSON, optional)", "Rate card (JSON)"), jargon fields
(method label, formula version, rate-card version, effective from), and a
version list with DRAFT/ACTIVE/INACTIVE badges plus Activate/Deactivate/Archive
buttons. The actual users are **not technical**: they cannot author JSON, don't
know what a "formula version" is, and only ever want *one* current setting.

## 2. User-directed requirements

1. One setting per page — no visible version list, no activate/archive controls.
2. Only a **Name** field survives; method label, formula version, rate-card
   version, effective-from disappear from the UI.
3. No JSON anywhere — replace with discrete fields the user can pick/toggle
   ("calculator-like" builder: choose which interactions count, set what each
   is worth, live formula preview).
4. Both EMV and reach are configurable **per platform** (Instagram, TikTok, YouTube).
5. Both pages show the formula plainly, including the downstream campaign math
   for EMV: `total_emv = Σ organic_emv`, `roi = total_emv / campaign_cost`,
   `organic_rate = organic_posts / products_sent`,
   `cost_per_mention = campaign_cost / organic_posts`.
6. Reach page states clearly that Instagram and TikTok reach is **always an
   estimate** (story view counts are not public).

## 3. What does NOT change (load-bearing invariants)

- `EmvConfigurationService` / `ReachConfigurationService`, both validators, both
  policies, models, migrations, calculators, estimators: **untouched**.
- Versioning + immutability stay: every save authors a **new version** and
  activates it atomically (old ACTIVE flips to INACTIVE inside the service's
  transaction-backed `activate()`), so past results stay reproducible
  (AC-M1-011) and audit logging keeps firing. The UI simply stops *showing*
  the version ledger.
- Controlled formula models stay: EMV is only `rate_card_sum`; reach is only
  `round(view_weight×views + follower_weight×followers)`; follower weight > 0.
- Authorization unchanged: page view `settings.view`; saving re-authorizes
  `emv.manage` / `reach.manage` (ADMIN) inside the services. Non-admins get a
  read-only rendering (disabled fields, no Save button, informational note).

## 4. Design — Reach page ("Reach settings")

Single card stack, one primary action (**Save changes**):

1. **How it works** panel — plain sentence + formula strip:
   `Estimated reach = (views × view %) + (followers × follower %)`.
   Info callout: *"Reach is always an estimate — Instagram and TikTok never
   publish how many people saw a story, so QDS models reach from public views
   and follower counts instead of claiming exact numbers."*
2. **Weights editor** — percentage inputs (0–100, step 5), NOT raw 0–1 floats:
   - Toggle **"Same weights for every platform"** (default on → one row).
   - Off → three rows (Instagram, TikTok, YouTube), each with `% of views
     counted` and `% of followers counted`.
   - Each row shows a live worked example: *"10,000 views + 20,000 followers →
     ≈ 9,000 people"*.
   - Follower % must be > 0; friendly inline error explains why (never a raw
     view count).
3. **Name** input (small, "shown in reports").
4. **Save changes** → builds `params` (weights ÷ 100; per-platform map when
   customized), auto-generates `formula_version = reach-YYYYMMDD-HHMMSS`,
   `method = qds-estimated-reach`, `effective_from = today`, then
   `create()` + `activate()` in one `DB::transaction`. Toast: "Reach settings
   saved — new posts use them right away."
5. If no ACTIVE config exists: banner "Reach is currently off — save these
   settings to turn it on"; Save button reads **Save & turn on**.

Hydration: from the tenant's ACTIVE config (`params.platforms` present →
per-platform mode, rows = override-or-default). Defaults when none: 70% / 10%.
When per-platform mode is saved, all three platforms are written explicitly and
`params.view_weight/follower_weight` mirror the **first platform row** — a
visible, user-validated pair — so any future platform without an override
still gets weights that passed validation (the hidden "all platforms" values
are never persisted unvalidated).

Numeric input: rate/percent fields are `type="text" inputmode="decimal"` and
the server accepts a comma as the decimal separator (French-locale users);
`type="number"` is avoided because its badInput state submits `''` and would
silently drop values. Hydration formats values at the same precision saves
store (weights 6 decimals ⇔ percents 4; EMV rates 6), so a reload-then-save
never mutates stored numbers.

## 5. Design — EMV page ("EMV settings")

1. **What is EMV** panel — one sentence (*"EMV estimates what a creator's post
   would have cost as paid advertising"*) + live formula preview built from
   current state: `EMV per post = views × €0.010 + likes × €0.050 + …` and a
   worked example (*"10,000 views, 500 likes, 40 comments → ≈ €133"*).
2. **Which interactions earn value?** — toggle chips (`aria-pressed` buttons)
   for views, plays, likes, comments, shares, saves. Toggling a chip adds/
   removes its rate field and its term in the preview (the "calculator" feel;
   deliberate substitute for literal drag-and-drop — same mental model, no
   fragile JS).
3. **What each interaction is worth** — currency select (EUR/USD/GBP) + one
   money input per selected metric ("€ per view", "€ per like", …).
4. **Fine-tune by platform** (collapsed by default) — grid platforms ×
   selected metrics; blank cell = use base rate. Maps to `rates.platforms`.
5. **Fine-tune by content format** (collapsed) — same grid over ContentType
   (Reel, Video, Image post, Carousel, Short, Live). Maps to
   `rates.content_types`. (Market/country multipliers stay unsupported in v1 —
   content has no geo attribution; validator already rejects them.)
6. **How QDS uses EMV** — read-only card with the four campaign formulas from
   §2.5 in plain language (Total EMV, ROI, Organic rate, Cost per mention).
7. **Name** + **Save changes** — same new-version-and-activate flow;
   auto `formula_version = emv-YYYYMMDD-HHMMSS`,
   `rate_card_version = rates-YYYYMMDD-HHMMSS`, `effective_from = today`.
8. Same "EMV is currently off" banner / read-only non-admin treatment.

Friendly pre-validation in the component (before the service's technical
validator): at least one interaction selected; every selected interaction has a
non-negative rate; follower % > 0 (reach). Service validator remains the
authoritative backstop.

## 6. Implementation shape

- New Livewire components replace the old ones (old files deleted):
  - `App\Modules\Monitoring\Livewire\Emv\EmvSettings` → view
    `livewire.monitoring.emv-settings`, registered `monitoring.emv-settings`.
  - `App\Modules\Monitoring\Livewire\Reach\ReachSettings` → view
    `livewire.monitoring.reach-settings`, registered `monitoring.reach-settings`.
- `MonitoringServiceProvider`, `settings/emv.blade.php`, `settings/reach.blade.php`
  updated; page titles "EMV settings" / "Reach settings"; routes unchanged.
- Duplicate `formula_version` unique-violation catch kept (friendly retry
  message) for both pages.
- Rate/weight inputs are strings in Livewire state (matches existing pattern),
  cast on save.

## 7. Testing

- Delete `ReachFormulaIndexTest`; add `ReachSettingsTest` + `EmvSettingsTest`:
  - admin save → ACTIVE config with correct params/rates (incl. per-platform
    + per-format maps, percent→weight conversion, blank cells omitted);
  - saving twice → two versions, only the newest ACTIVE (history preserved);
  - friendly errors: zero follower %, no metrics selected, missing rate;
  - non-admin: read-only render, save forbidden;
  - hydration from existing ACTIVE config (round-trip).
- `SettingsRoutesTest` / `SettingsNavTest` unchanged (routes stable); update
  page-title assertions if any.
- Full suite with `XDEBUG_MODE=off`.

## 8. Out of scope

- No service/validator/schema changes; no new permissions; no version-history
  browser UI (data stays queryable); no drag-and-drop library.
