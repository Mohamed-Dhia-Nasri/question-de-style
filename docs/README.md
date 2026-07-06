---
id: README
title: "Question de Style — Documentation Router"
status: APPROVED
canonical_for: []
depends_on:
  - docs/AGENTS.md
  - docs/00-meta/00-index.md
last_reviewed: 2026-07-02
---

# Question de Style (QDS) — Documentation

Question de Style (QDS) is an AI-powered influencer-intelligence platform built for a DACH (German-speaking) influencer-marketing agency. It monitors public social content, discovers and evaluates creators, and manages creator relationships and product-seeding campaigns, applying a strict provenance-first and confidence-first data doctrine throughout. This documentation set exists so that AI coding agents can implement the platform without conflicts, ambiguity, scope drift, or hallucination.

This file is a **thin router only** — it contains no domain facts. Every fact lives in exactly one canonical file; find it via the router in [00-meta/00-index.md](00-meta/00-index.md).

## The Three Modules

The platform is exactly three modules — no more, no fewer.

- **Module 1 — Monitoring & Reporting**: tracks brand/campaign/keyword mentions across public content, classifies and measures them, and reports. See [50-modules/module-1-monitoring.md](50-modules/module-1-monitoring.md).
- **Module 2 — Discovery**: searches, profiles, scores, and compares creators for suitability. See [50-modules/module-2-discovery.md](50-modules/module-2-discovery.md).
- **Module 3 — CRM & Seeding**: is the system of record for creator identity, contacts, campaigns, seeding, and permissions. See [50-modules/module-3-crm-seeding.md](50-modules/module-3-crm-seeding.md).

## Start Here

> **Two entry points — read both before writing any code or docs.**
>
> 1. **[AGENTS.md](AGENTS.md)** — the binding operating rules for AI agents. Read this first; it governs how you work in this tree.
> 2. **[00-meta/00-index.md](00-meta/00-index.md)** — the master file map, agent reading order, and fact-location router. Use it to find the single canonical home of any fact.

## Status-Marker Legend

Every documented item carries a status. Status controls whether it may be built. The status vocabulary and full build-permission rules are canonical in [00-meta/02-status-lifecycle.md](00-meta/02-status-lifecycle.md); the enum values are canonical in [00-meta/03-glossary.md](00-meta/03-glossary.md#enum-docstatus). The markers you will see:

| Marker | Meaning (short) | Buildable? |
| --- | --- | --- |
| DRAFT | Work in progress | No — not a fact, do not build |
| PROPOSED | Under consideration | No — not a fact, do not build |
| APPROVED | Accepted for v1 | Only when its roadmap phase is active |
| IMPLEMENTED | Built and verified | Changes need an ADR + changelog |
| DEFERRED | Out of v1 scope | No — UI shows "unavailable" (never empty/zero) |
| DEPRECATED | Retired | No |
| SUPERSEDED | Replaced | No |

See [00-meta/02-status-lifecycle.md](00-meta/02-status-lifecycle.md) for the authoritative definitions, the phase-gate rule, and the deferred-item UI rule.

## The Hard Rule

**Every fact has ONE canonical home — link, never restate.**

If a fact (an enum, an entity's fields, a write-owner, a source, a deferred item, a decision) is canonical in another file, reference it with a relative link instead of copying it here or anywhere else. Restating a canonical fact is a lint failure. Cross-reference only the files listed in [00-meta/00-index.md](00-meta/00-index.md); never link to a path outside that set.