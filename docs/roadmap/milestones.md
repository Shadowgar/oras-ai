# Roadmap and Milestone Acceptance Checklist

## Legend
- RB: release blocker
- RD: required before real member deployment
- QT: qualified target
- DC: deferred capability

## M0 — Architecture approved
**Status:** ACCEPTED — 2026-08-27
- [x] RB Architecture, requirements, threat model, integrations, quality plan, ADRs, and roadmap exist.
- [x] RB Owner resolves open M0 decisions.
- [x] RB ADRs change Proposed → Accepted.
- [x] RB Owner approval/freeze evidence recorded.
- [x] RB Traceability links validate.

## M1 — Plugin foundation stabilized
**Status:** COMPLETE — 2026-08-31

**Evidence:** [M1 plugin foundation closure verification](../evidence/m1-plugin-foundation/verification.md)
- [x] RB v0.2.1 source under Git with reproducible versioning.
- [x] RB Retained prototype behavior has regression coverage.
- [x] RB Module boundaries implemented.
- [x] RB Secret/config baseline implemented.
- [x] RB Admin kill switch.
- [x] RB PHP/JS lint and automated tests.

## M2 — Knowledge platform qualified
**Status:** COMPLETE — 2026-09-03

**Evidence:** [M2 knowledge platform closure verification](../evidence/m2-knowledge-platform/verification.md)
- [x] RB Rule-first ingestion.
- [x] RB Mixed-source extraction.
- [x] RB Provenance/hash/lifecycle.
- [x] RB Idempotent normal sync/rebuild.
- [x] RB Retired entries excluded from active counts/retrieval.
- [x] RB Manual entries protected.
- [x] RB Review queue usable.
- [x] RB Scanner tests pass.

## M3 — Retrieval, security, and cost boundary proven
- [ ] RB Source-linked retrieval.
- [ ] RB Fact-level source precedence.
- [ ] RB Authentication/member authorization.
- [ ] RB Domain guard.
- [ ] RB Prompt-injection tests.
- [ ] RB Quota/burst/input/output limits.
- [ ] RB Usage/audit baseline.
- [ ] RB No-evidence ORAS question does not hallucinate.

## M4 — Member chat UX qualified
- [ ] RB Dedicated member chat.
- [ ] RB Member-only availability.
- [ ] RB Progress/error UX.
- [ ] RB Source/action rendering.
- [ ] RB Accessibility tests.
- [ ] RB Privacy/retention decision implemented.
- [ ] RB Admin test console.

At M4 the assistant may answer stable ORAS knowledge and general astronomy, but cannot claim unqualified live capabilities.

## M5 — Live ORAS integrations
- [ ] RB Events Calendar connector.
- [ ] RB WooCommerce connector.
- [ ] RB PMPro connector.
- [ ] RB Live/static conflict resolution.
- [ ] RB Canonical action links.
- [ ] RB No autonomous purchase behavior.

## M6 — Astronomy/weather intelligence
- [ ] RB Astronomy provider/library selected with qualification evidence against the M0 capability contract.
- [ ] RB Weather provider selected with qualification evidence against the M0 capability contract.
- [ ] RB Observatory location/time-zone correctness.
- [ ] RB Current sky calculations.
- [ ] RB Weather freshness/uncertainty.
- [ ] RB Best-night recommendation workflow.
- [ ] QT Latency/cost measured.

## M7 — Fluent Support escalation/feedback
- [ ] RB Fluent Support bridge qualified against installed version.
- [ ] RB Routing works.
- [ ] RB Explicit confirmation.
- [ ] RB Duplicate/error handling.
- [ ] RB Ticket data minimization.
- [ ] RB Knowledge-gap candidate workflow without auto-approval.

## M8 — Member-aware actions
- [ ] RB Member-specific answers use least privilege.
- [ ] RB Pass/event recommendations use live availability.
- [ ] RB Side-effect confirmation.
- [ ] DC Direct payment automation remains prohibited unless separately approved.

## M9 — Production release
- [ ] RD Security review.
- [ ] RD Privacy/retention communication.
- [ ] RD Backup/rollback tested.
- [ ] RD Cost budgets/alerts.
- [ ] RD Evaluation thresholds accepted.
- [ ] RD Monitoring/kill switch.
- [ ] RD Public anonymous AI remains disabled unless superseded by ADR.
- [ ] RB Owner accepts production evidence.
