# Non-Functional Requirements

## Security
- **NFR-SEC-001 [RB/RD]** Secrets remain server-side.
- **NFR-SEC-002 [RB/RD]** Protected/state-changing endpoints use authorization and CSRF controls.
- **NFR-SEC-003 [RB/RD]** Retrieved content is untrusted data, not policy.
- **NFR-SEC-004 [RB/RD]** User URLs cannot trigger unrestricted server fetches.
- **NFR-SEC-005 [RB/RD]** External member context is minimized.
- **NFR-SEC-006 [RB]** Sensitive admin changes are auditable.
- **NFR-SEC-007 [RB]** Routine logs redact secrets and unnecessary message content.

## Reliability
- **NFR-REL-001 [RB]** Optional connector failure does not break unrelated workflows.
- **NFR-REL-002 [RB]** Scanner jobs are safely repeatable/resumable.
- **NFR-REL-003 [RB]** Sync avoids duplicate managed artifacts.
- **NFR-REL-004 [RB]** Retired knowledge is excluded from retrieval.
- **NFR-REL-005 [RB]** Tool timeouts/retries are bounded.

## Performance
- **NFR-PERF-001 [QT]** Stable-knowledge answers target interactive web-chat latency.
- **NFR-PERF-002 [QT]** Multi-source observing recommendations may be slower but need clear progress state.
- **NFR-PERF-003 [QT]** Retrieval uses bounded top-K/chunk budgets.
- **NFR-PERF-004 [QT]** Unchanged sources avoid AI reclassification.

## Accessibility
- **NFR-A11Y-001 [RB]** Chat controls are keyboard operable.
- **NFR-A11Y-002 [RB]** Status/errors are exposed accessibly.
- **NFR-A11Y-003 [RB]** Color is not the sole state indicator.
- **NFR-A11Y-004 [RB]** Links/buttons have descriptive accessible names.

## Privacy
- **NFR-PRIV-001 [RB/RD]** AI conversation text is retained for 30 days and then automatically deleted from ORAS AI conversation storage; usage/cost metadata is retained for 12 months without full conversation text.
- **NFR-PRIV-002 [RB]** Members are informed that external AI processing is used.
- **NFR-PRIV-003 [RB]** Ticket linkage contains only necessary context.
- **NFR-PRIV-004 [RB]** Payment-card data is never intentionally sent to AI.

## Maintainability / observability
- **NFR-MNT-001 [RB]** Provider-specific code is isolated behind adapters.
- **NFR-MNT-002 [RB]** Post-freeze architectural changes require ADR updates.
- **NFR-MNT-003 [RB]** Classification rules are versioned/testable.
- **NFR-OBS-001 [RB]** Scan runs/outcomes are recorded.
- **NFR-OBS-002 [RB]** Connector failures are countable.
- **NFR-OBS-003 [RB]** Rate/domain rejection counts are observable without unnecessary prompt storage.
- **NFR-OBS-004 [RB]** Repeated source failures/review items are visible to admins.
