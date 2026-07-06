# Deep-Review Handoff Files

This folder holds **deep-review handoff files** — one per major implementation. Each file
is the persisted, checkbox-tracked form of the "Review Handoff" required by §4 of the
**AI Agent Execution and Review Policy** (`AGENT-ExecutionPolicy`).

These are **working artifacts, not canonical spec.** They live here — *outside* `docs/` —
on purpose: the `docs/` tree is governed by the `check-docs` linter (required frontmatter,
`ENUM-DocStatus`, traceability). Review files are not spec and must not be linted as such.

## Why this exists

The implementation model and the review model are **separate tasks, run by different
models** (policy §2). The implementation model must not review its own work with parallel
or adversarial agents. Instead, at the end of a major implementation it writes one of these
files describing *what a reviewer should check, where to focus, and which scenarios to run*.
You then hand the file to a review model (Opus / Opus XtraCode) and say: "do the deep review
described in this file, marking each item done."

## When a file is created

The implementation model writes a review file after a **major implementation** — i.e. any
change matching a policy §7 deep-review trigger:

- affects canonical architecture,
- modifies security or personal-data handling,
- introduces database migrations with destructive risk,
- changes entity ownership,
- modifies authentication or authorization,
- is intended for a production release,
- or is otherwise a large / multi-file feature step.

Small, self-contained edits do **not** get a review file — the concise end-of-turn handoff
in the chat is enough.

## Naming

```
reviews/REVIEW-<slug>-<YYYY-MM-DD>.md
```

`<slug>` = short kebab-case name of the implementation (e.g. `module1-foundation`,
`auth-rbac`, `reporting-migrations`). Example:

```
reviews/REVIEW-module1-foundation-2026-07-04.md
```

## Review-status axis

The file's `review_status` is **separate** from `ENUM-DocStatus` (which governs spec
buildability and must not be reused here):

| review_status     | Meaning                                             |
| ----------------- | --------------------------------------------------- |
| `PENDING_REVIEW`  | Written by the implementer; awaiting a review model |
| `IN_REVIEW`       | A review model is working through the checklist     |
| `REVIEWED`        | Review complete; see `outcome`                      |

`outcome` (set when `REVIEWED`): `ACCEPTED` or `CHANGES_REQUESTED`.

## How to use one

1. **Implementation model** copies `_TEMPLATE.md`, fills every section, leaves all review
   checkboxes as `[ ]`, sets `review_status: PENDING_REVIEW`.
2. **You** hand the file to a review model: *"Do the deep review described in
   `reviews/REVIEW-...md`. Work the checklist, run the suggested scenarios, mark each item
   `[x]` as you verify it, and record findings."*
3. **Review model** sets `review_status: IN_REVIEW`, checks items off, fills the
   *Reviewer Findings* section, then sets `review_status: REVIEWED` + `outcome`.

See [`_TEMPLATE.md`](_TEMPLATE.md).
