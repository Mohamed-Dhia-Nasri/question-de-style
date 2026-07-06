---
id: TPL-ModuleSpec
title: Module Spec Template
status: APPROVED
canonical_for:
  - module-spec-template
depends_on:
  - ../00-meta/01-conventions.md
  - ../00-meta/02-status-lifecycle.md
  - ../00-meta/03-glossary.md
  - ../70-shared/00-ownership-matrix.md
  - ../30-data-model/00-data-model.md
  - ../40-integrations/00-data-source-matrix.md
  - ../20-cross-cutting/00-data-principles.md
  - ../20-cross-cutting/01-deferred-register.md
  - ../60-architecture/00-system-architecture.md
last_reviewed: 2026-07-02
---

# Module Spec Template

This is the **authoritative template** that every module spec MUST follow. The three
module specs — [Module 1 Monitoring](module-1-monitoring.md),
[Module 2 Discovery](module-2-discovery.md), and
[Module 3 CRM & Seeding](module-3-crm-seeding.md) — each fill in exactly the six
sections defined here, in this order, with no sections added or removed.

This file is a template: `<angle-bracket placeholders>` mark spots an author fills in.
The single worked example in [Section 4](#4-user-stories--acceptance-criteria) uses the
reserved placeholder IDs `REQ-Mx-001` / `AC-Mx-001` and MUST NOT be copied verbatim into
a real spec.

## How to use this template

1. Copy the frontmatter block and the six section headings verbatim.
2. Replace every `<placeholder>` with concrete content for your module.
3. Never restate a canonical fact. If you are tempted to list enum values, entity fields,
   write-owners, source capabilities, deferred items, or decisions, **link instead** —
   see the [single-source-of-truth rules in conventions](../00-meta/01-conventions.md).
4. Obey the ID grammar and cross-reference syntax defined in
   [conventions](../00-meta/01-conventions.md); enum names/anchors live in the
   [glossary](../00-meta/03-glossary.md).

### Required frontmatter (per module spec)

| Field | Rule |
| --- | --- |
| `id` | Module identifier, e.g. `MOD-Monitoring`. |
| `title` | Human title of the module. |
| `status` | One [ENUM-DocStatus](../00-meta/03-glossary.md#enum-docstatus) value. Buildable only when `APPROVED` **and** its phase is active in the [roadmap](../80-delivery/00-roadmap.md) — see [status lifecycle](../00-meta/02-status-lifecycle.md). |
| `canonical_for` | Fact-classes / ID-prefixes this spec owns (e.g. its own `REQ-M<n>-*` and `AC-M<n>-*`). `[]` if none. |
| `depends_on` | Real IDs or real file paths only (F10). |
| `last_reviewed` | `2026-07-02`. Never a date later than 2026-07-02 (F10). |

---

## 1. Purpose

`<One or two paragraphs: what business problem this module solves, for whom, and where it
sits in the platform. Assume a reader with zero business context.>`

State the module boundary by linking, not restating: reference the
[system architecture module boundaries](../60-architecture/00-system-architecture.md) and
name the owning internal service (`SVC-<PascalName>`) summarized there.

---

## 2. In-scope Features

List every Active feature this module delivers as `REQ-M<n>-<NNN>` rows. The canonical
scope map (feature → sources → Active/Deferred → REQ-ID) lives in
[modules-overview](../10-product/01-modules-overview.md); this table is the module-local
view and MUST NOT contradict it.

| REQ-ID | Feature | Status |
| --- | --- | --- |
| `REQ-M<n>-<NNN>` | `<short feature name>` | `<`[ENUM-DocStatus](../00-meta/03-glossary.md#enum-docstatus)` value>` |
| `REQ-M<n>-<NNN>` | `<short feature name>` | `<ENUM-DocStatus value>` |

> **Note:** `status` is the DocStatus of the feature, not a build order. Build order is
> governed by the [roadmap](../80-delivery/00-roadmap.md) phases.

---

## 3. Out-of-scope / Deferred

Anything this module intentionally does **not** build in v1. Every deferred item MUST map
to a `DEF-*` in the canonical [deferred register](../20-cross-cutting/01-deferred-register.md);
link it, do not restate its rationale here.

| Deferred item | Register entry |
| --- | --- |
| `<capability name>` | [DEF-<NNN>](../20-cross-cutting/01-deferred-register.md) |

Deferred fields are never rendered as empty or zero — see the
[unavailable-not-empty UI rule](#5-ui-screens--states).

---

## 4. Data owned & sources consumed

### 4.1 Data owned

Do **not** restate entity fields — those are canonical only in the
[data model](../30-data-model/00-data-model.md) (F6). Do **not** restate write-ownership —
that is canonical only in the
[ownership matrix](../70-shared/00-ownership-matrix.md) (F2). This section only *links* to
both.

| Entity | Role in this module | Canonical field shape | Write-owner (tiebreaker) |
| --- | --- | --- | --- |
| [ENT-`<PascalName>`](../30-data-model/00-data-model.md) | `<writes / reads>` | [data model](../30-data-model/00-data-model.md) | [ownership matrix](../70-shared/00-ownership-matrix.md) |

Every externally-sourced record this module writes carries the `Provenance` envelope, and
every inferred/estimated value carries a `ConfidenceAssessment` — per
[DP-002](../20-cross-cutting/00-data-principles.md) and
[DP-003](../20-cross-cutting/00-data-principles.md). Envelope shapes are defined in the
[data model](../30-data-model/00-data-model.md).

### 4.2 Sources consumed

Reference sources by their `SRC-*` id only; capabilities are canonical in the
[data source matrix](../40-integrations/00-data-source-matrix.md) (do not restate them).

| Source | Used for | Contract |
| --- | --- | --- |
| `SRC-<kebab>` | `<what this module pulls from it>` | [data source matrix](../40-integrations/00-data-source-matrix.md) |

---

<a id="4-user-stories--acceptance-criteria"></a>

## 5. User stories & acceptance criteria

Each user story is keyed to a `REQ-M<n>-<NNN>`. Each acceptance criterion uses
**Given / When / Then** form, carries an `AC-M<n>-<NNN>` id, and **cites the data
principle** (`DP-*`) it enforces. Acceptance-criteria format is defined in
[conventions](../00-meta/01-conventions.md).

### Worked example (placeholder — replace in a real spec)

> This example uses the reserved placeholder IDs `REQ-Mx-001` / `AC-Mx-001` so it can
> never collide with a real requirement (F5).

**Story `REQ-Mx-001`:** As an `<analyst>`, I want the platform to classify each detected
brand mention so that `<I can separate paid placements from likely-organic buzz>`.

| AC-ID | Given | When | Then | Enforces |
| --- | --- | --- | --- | --- |
| `AC-Mx-001` | a newly ingested [ENT-`<Example>`](../30-data-model/00-data-model.md) record with a `Provenance` envelope | the [SVC-EnrichmentAI](../60-architecture/00-system-architecture.md) classifier runs | the record receives a `ConfidenceAssessment` whose `verificationStatus` is [`AI_ASSESSED`](../00-meta/03-glossary.md#enum-verificationstatus), and a human reviewer can correct it (result stored as `HUMAN_CORRECTED`) | [DP-003](../20-cross-cutting/00-data-principles.md), [DP-004](../20-cross-cutting/00-data-principles.md) |

> The valid AI verification value is `AI_ASSESSED` (F9) — never `AII_ASSESSED`. All enum
> values are canonical in the [glossary](../00-meta/03-glossary.md).

`<Add one story block per Active REQ in Section 2. Every AC row MUST cite at least one
DP-*.>`

---

<a id="5-ui-screens--states"></a>

## 6. UI screens & states, AI behaviours, dependencies, open questions

### 6.1 UI screens & states

For every screen, specify all of: **empty**, **loading**, **error**, and the populated
state. Deferred fields follow the **unavailable-not-empty** rule.

| Screen | Empty | Loading | Error | Deferred-field rendering |
| --- | --- | --- | --- | --- |
| `<screen name>` | `<what shows with no data yet>` | `<skeleton / spinner behaviour>` | `<retry / error message behaviour>` | any [DEF-*](../20-cross-cutting/01-deferred-register.md) field renders the literal **"unavailable"** — never empty and never `0` |

> **Unavailable-not-empty rule (F-canonical):** a deferred field must render "unavailable",
> never blank or zero. This is the canonical [UI rule in the deferred register](../20-cross-cutting/01-deferred-register.md).
> Metric tiers must also be shown honestly: `ESTIMATED` values are labelled as estimates,
> never presented as fact — see [DP-001](../20-cross-cutting/00-data-principles.md) and the
> [MetricTier enum](../00-meta/03-glossary.md#enum-metrictier).

### 6.2 AI behaviours & human-review points

List each AI-produced output, the source signals, and the mandatory human-in-the-loop hook
required by [DP-004](../20-cross-cutting/00-data-principles.md).

| AI behaviour | Produced by | Confidence output | Human-review trigger |
| --- | --- | --- | --- |
| `<e.g. sentiment / classification / recognition>` | [SVC-EnrichmentAI](../60-architecture/00-system-architecture.md) | `ConfidenceAssessment` ([verificationStatus](../00-meta/03-glossary.md#enum-verificationstatus) starts at `AI_ASSESSED`) | `<low-confidence → review queue; corrections stored and fed back per DP-004>` |

### 6.3 Dependencies on other modules

Cross-module data flows go through **cross-module contracts** (`XMC-*`); the contract
concept and module boundaries are defined in the
[system architecture](../60-architecture/00-system-architecture.md). Never write another
module's owned entity directly — route through its owner per the
[ownership matrix](../70-shared/00-ownership-matrix.md).

| Depends on | Direction | Mechanism |
| --- | --- | --- |
| `<Module N>` | `<reads from / proposes to>` | `XMC-<NNN>` — [cross-module contract](../60-architecture/00-system-architecture.md) |

> Example dependency pattern: M1 and M2 **propose** new creators via a cross-module
> contract; they do not write `Creator` directly, because the
> [ownership matrix](../70-shared/00-ownership-matrix.md) assigns `Creator` writes to M3.

### 6.4 Open questions

Track unresolved decisions here. A resolved question that changes scope or stack becomes an
ADR in the canonical [decision log](../05-decisions/decision-log.md) — do not record
decisions in this spec.

| # | Question | Owner | Blocking? |
| --- | --- | --- | --- |
| `<n>` | `<open question>` | `<role>` | `<yes / no>` |