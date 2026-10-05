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
**Status:** COMPLETE — 2026-09-03

**Evidence:** [M3 retrieval, security, and cost closure verification](../evidence/m3-retrieval-security-cost/verification.md)
- [x] RB Source-linked retrieval.
- [x] RB Fact-level source precedence.
- [x] RB Authentication/member authorization.
- [x] RB Domain guard.
- [x] RB Prompt-injection tests.
- [x] RB Quota/burst/input/output limits.
- [x] RB Usage/audit baseline.
- [x] RB No-evidence ORAS question does not hallucinate.

## M4 — Member chat UX qualified
**Status:** COMPLETE — 2026-09-03

**Evidence:** [M4 member chat UX closure verification](../evidence/m4-member-chat-ux/verification.md)
- [x] RB Dedicated member chat.
- [x] RB Dedicated page exposes the shared chat through `[oras_ai_chat]`.
- [x] RB Site-wide eligible-member **Support** launcher opens the plugin-owned overlay/panel.
- [x] RB Dedicated page and floating panel share one chat component, transport, authorization, renderer, and backend.
- [x] RB Member-only availability.
- [x] RB Refresh/navigation restores the current/latest conversation and **New Chat** starts a fresh one.
- [x] RB Progress/error UX.
- [x] RB Source/action rendering (M4 qualifies trusted source links; member actions remain M8).
- [x] RB Accessibility tests.
- [x] RB Privacy/retention decision implemented.
- [x] RB Admin test console.

At M4 the assistant may answer stable ORAS knowledge and general astronomy, but cannot claim unqualified live capabilities.

## M5 — Live ORAS integrations

**Status:** COMPLETE — 2026-09-08

**Evidence:** [M5 closure verification](../evidence/m5-live-oras-integrations/verification.md)

- [x] RB Events Calendar connector.
- [x] RB WooCommerce connector.
- [x] RB PMPro connector.
- [x] RB Live/static conflict resolution.
- [x] RB Canonical action links.
- [x] RB No autonomous purchase behavior.

## M6 — Astronomy/weather intelligence
- [x] RB Astronomy provider/library selected with qualification evidence against the M0 capability contract.
- [x] RB Weather provider selected with qualification evidence against the M0 capability contract.
- [x] RB Observatory location/time-zone correctness.
- [x] RB Current sky calculations.
- [x] RB Weather freshness/uncertainty.
- [x] RB Best-night recommendation workflow.
- [x] QT Latency/cost measured.

M6 implementation qualification: **READY TO CLOSE**, 2026-09-27. See
[verification evidence](../evidence/m6-astronomy-weather-intelligence/verification.md).
Weekend comparisons preserve authoritative interval scores and disclose the
absence of a whole-night aggregate. Credentialed AstronomyAPI and installed-site
verification remain deployment items; ASTRO-007 remains deferred. The original
closure evidence was committed at `657ed77`; this final rerun makes no new
commit or production-release claim.

## M7 — Fluent Support escalation/feedback
**Status:** COMPLETE — frozen development qualification committed at `039d6ae`.

**Evidence:** [M7 Fluent Support qualification](../evidence/m7-fluent-support-escalation/verification.md)
- [x] RB Fluent Support bridge qualified against disposable core/Pro 2.4.0.
- [x] RB Routing works.
- [x] RB Explicit confirmation, including distinct metered AI support summary in preview and ticket payload.
- [x] RB Duplicate/error handling, with ambiguous create held uncertain and never retried automatically.
- [x] RB Ticket data minimization.
- [x] RB Admin-initiated resolved-ticket knowledge candidate enters Needs Review without auto-approval.

The M7 qualification and SUP-004 correction are committed. Its production
configuration and credentialed-provider verification remain deployment items;
the M8 qualification reruns its confirmation and duplicate-safety regressions.

## M8 — Member-aware actions
**Status:** COMPLETE — frozen development qualification, 2026-10-01.

**Closure decision:** READY TO CLOSE; closure documentation committed at `56b8ba9`.

**Evidence:** [M8 member-aware actions qualification](../evidence/m8-member-aware-actions/verification.md)
- [x] RB Member-specific answers use least privilege.
- [x] RB Pass/event recommendations use live availability.
- [x] RB Side-effect confirmation.
- [ ] DC Direct payment automation remains prohibited unless separately approved.

Qualification covers ACT-001–004, AT-ACTION-001–005, hostile-model grounding,
combined-domain preservation, and fact-scoped partial failure at implementation
HEAD `816da5adf311049f6a95a288a484d0e3337a417f`. All M1–M7 automated regressions
remain green. Production product/event mappings, prices, capacity, installed
versions, and canonical HTTPS handoffs remain deployment verification items.
Plugin version remains `0.2.1`; ASTRO-007 remains deferred.

## M9 — Production release
**Status:** IN PROGRESS — local baseline qualification; production release gates remain open.

Task 1 site-wide OpenAI accounting is committed at `ea1f9ea8`.
Task 2 retention and safe configuration is committed at `97c9a707`.
Local warning-free qualification is recorded in
[M9 local baseline evidence](../evidence/m9-local-baseline/verification.md).
These implementation checkpoints do not close the production release.

- [ ] RD Security review.
- [ ] RD Privacy/retention communication.
- [ ] RD Backup/rollback tested.
- [ ] RD Cost budgets/alerts.
- [ ] RD Evaluation thresholds accepted.
- [ ] RD Monitoring/kill switch.
- [ ] RD Public anonymous AI remains disabled unless superseded by ADR.
- [ ] RB Owner accepts production evidence.
