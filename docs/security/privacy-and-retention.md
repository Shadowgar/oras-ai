# Privacy and Retention

## Initial retention policy

- **AI conversation text:** retain for 30 days, then automatically delete from ORAS AI conversation storage.
- **Usage/cost metadata:** retain for 12 months. This may include user ID, timestamps, request counts, token/cost measurements, workflow type, and rate/domain events, but not the full conversation text.
- **Fluent Support tickets:** retained according to the separate ORAS/Fluent Support support-retention policy.
- **Support escalation:** only the confirmed ticket payload is copied into Fluent Support; expiration of ORAS AI chat history does not delete an independently retained support ticket.

The member interface should disclose the 30-day chat-retention period and explain that questions intentionally submitted to ORAS Support may be retained separately as support tickets.

## Principle

Retain only what is needed for service, abuse/cost control, troubleshooting, and member-requested escalation.

## Data classes

### Identity
WordPress user ID plus minimal eligibility context.

### Conversation
Member/assistant message text is retained for 30 days under the initial production policy, then automatically deleted from ORAS AI conversation storage.

### Usage telemetry
Counts, tokens, cost, timestamps, workflow class, and blocked/rejected events are retained for 12 months under the initial production policy. Full conversation text is not part of this telemetry.

### Support
Confirmed escalation moves necessary content into Fluent Support under ORAS support-retention practices.

## External AI exclusions

Do not send:
- passwords;
- API keys;
- payment-card data;
- unrelated billing data;
- unrelated private support tickets;
- complete user profiles by default.

## Conversation-to-ticket

Show the member what summary/original question will be submitted. Entire conversations are not attached by default.

## Analytics

Common-question analysis should prefer normalized/de-identified counts. Raw text retention for knowledge-gap analysis must be explicitly controlled.

## M4 implementation requirement

Implement automatic 30-day conversation expiration, 12-month usage/cost metadata retention, and the member-facing privacy disclosure before production release.

## Scheduled usage enforcement and recovery data

Usage retention uses **twelve calendar months**, preserving the existing PHP cutoff `strtotime('-12 months', now)`. Reservation metadata strictly older than that timestamp is eligible; an entry exactly at the cutoff remains until a later pass. This preserves PHP leap-day/month-end normalization (for example, 2024-02-29 minus twelve months normalizes to 2023-03-01). UTC rejection-month buckets are removed only when their month sorts before the cutoff month. Burst timestamps expire from their rolling one-minute window.

The native daily `oras_ai_prune_usage` callback processes bounded, rotating batches under the same atomic lock as reservations and settlement. Activity-triggered cleanup remains in place. Execution and backlog delays, host scheduler verification and inactive-plugin responsibilities are described in the [operations runbook](../operations/operations-runbook.md#usage-metadata-maintenance-and-idle-sites).

Expired settled records are removed unless they still carry current-month spend or the first accounting-failure recovery reference. Expired open/dispatched reservations and unknown-usage recovery records cannot simply be deleted: doing so could reopen paid-call headroom or lose later settlement. These records instead lose member IDs, quota identity, creation/dispatch times and all unrecognized fields. Only non-content accounting fields remain in the existing ledger: reservation reference, model/source/status, bounded token caps, price snapshot, measured or conservative cost/token values and the settlement month (normalized to its first day). No second ledger is introduced. Subsequent settlement of a redacted record preserves month-level timing without recreating personal activity timestamps. Once reconciled, past-month records without a fault reference can be deleted by a later batch. Unknown usage remains explicitly unknown; unresolved non-personal recovery state and the incident fence persist until accounting recovery is complete.

Local support escalation records have an existing **30-day lifecycle**. Confirmation proposals have a shorter actionable lifetime; deleting/expiring a local record does not delete a confirmed Fluent Support ticket. Fluent Support retention remains governed by its separate policy, without a new numeric period here. The shared member chat disclosure distinguishes 30-day conversation/local escalation storage, twelve-calendar-month usage metadata without conversation text, external AI processing, and separately retained confirmed support tickets.
