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
