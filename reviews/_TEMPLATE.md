<!--
  Deep-Review Handoff — TEMPLATE
  Copy to: reviews/REVIEW-<slug>-<YYYY-MM-DD>.md
  Written by the IMPLEMENTATION model. Consumed by a SEPARATE review model.
  Rules: fill every section. Leave all review checkboxes as [ ]. Do NOT self-review here.
-->

# Deep Review — <Implementation name>

- **review_status:** PENDING_REVIEW   <!-- PENDING_REVIEW | IN_REVIEW | REVIEWED -->
- **outcome:** —                       <!-- set on REVIEWED: ACCEPTED | CHANGES_REQUESTED -->
- **implemented_by:** <model id>
- **implementation_date:** <YYYY-MM-DD>
- **reviewer:** unassigned              <!-- review model id, filled at review time -->
- **deep_review_trigger:** <which policy §7 trigger(s) apply, or "major feature step">

---

## 1. Implementation summary

<2–5 sentences: what was built and the scope boundary — what is explicitly IN and OUT.>

## 2. Changed files

<!-- One line each. Group by area. -->
- `path/to/file` — what changed and why.

## 3. Canonical documents relied upon

<!-- The exact docs/ files (with IDs) whose facts this implementation depends on. -->
- `docs/...` (`ID`) — which fact it anchored.

## 4. Known deviations / open conflicts

<!-- Anything that differs from canon, or a canonical conflict found (policy §8:
     report, name the files, propose smallest fix, do NOT change canon unilaterally). -->
- <deviation or conflict, or "None.">

## 5. Tests & checks

- **Executed (passing):** <tests / phpstan / pint / migrations / build — with commands.>
- **Executed (failing / skipped):** <name them and why.>
- **Not executed:** <what was not run and why.>
- **External integrations not verified:** <APIs, queues, third-party — anything unproven locally.>

---

## 6. Review checklist

> Reviewer: mark `[x]` only after you have **verified** the item. Add a note per item.
> Categories mirror policy §2 (Review Model responsibilities).

### 6.1 Canonical fidelity
- [ ] Implementation matches the cited canonical docs (statuses, entities, enums, ownership).
- [ ] No canonical fact was silently changed to make the code pass.

### 6.2 Architecture & ownership
- [ ] Respects module boundaries and the ownership matrix.
- [ ] No cross-module reach-through or leaked responsibility.

### 6.3 Security & personal data
- [ ] AuthN/AuthZ correct for every new surface (routes, actions, policies).
- [ ] Personal-data handling matches data principles; no over-collection / leak.

### 6.4 Correctness
- [ ] Core logic is correct on the happy path and documented edge cases.
- [ ] Error/empty/unavailable states behave per spec (e.g. unavailable-never-empty).

### 6.5 Migrations & database
- [ ] Migrations are reversible or the destructive risk is called out and accepted.
- [ ] Schema matches the data model; constraints / indexes / FKs are correct.

### 6.6 Test adequacy
- [ ] Tests cover the new behavior, not just the happy path.
- [ ] Failure modes and boundaries are asserted.

### 6.7 Adversarial verification
- [ ] Each finding below was independently verified (not taken on first read).

---

## 7. Suggested review scenarios — where to focus

> Concrete things for the reviewer to actually run/trace. Rank by risk.

1. **<Scenario name>** — *Focus:* <area/file>. *Do:* <steps / input>. *Expected:* <result>.
   *Risk if wrong:* <impact>.
2. **<Scenario name>** — ...

## 8. High-risk areas

<!-- The 2–4 places most likely to hide a defect. Be specific: file + why. -->
- `path` — <why it's risky.>

---

## 9. Reviewer findings

> Filled by the REVIEW model. One checkbox per finding; check when resolved/dispositioned.

- [ ] **<severity>** `path:line` — <finding>. *Disposition:* <fix required | acceptable | false-positive>.

## 10. Review sign-off

- **Reviewer:** <model id>
- **review_status → REVIEWED on:** <YYYY-MM-DD>
- **outcome:** <ACCEPTED | CHANGES_REQUESTED>
- **Summary:** <one paragraph: overall verdict + any must-fix items.>
