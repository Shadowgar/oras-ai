# M0 Decision 003 — Conversation and Usage Retention

**Status:** Resolved during M0 review

## Decision

| Data class | Initial retention |
|---|---|
| ORAS AI conversation text | 30 days |
| Usage/cost metadata | 12 months |
| Fluent Support ticket content | Separate ORAS/Fluent Support support-retention policy |

After 30 days, ORAS AI conversation text is automatically deleted from ORAS AI conversation storage.

Usage/cost metadata may retain user ID, timestamps, request counts, token/cost measurements, workflow type, and abuse/rate-limit telemetry for 12 months, but does not retain the full conversation text.

If a member explicitly confirms escalation to Fluent Support, the confirmed ticket content becomes a separate support record and is not deleted merely because the originating ORAS AI chat reaches its 30-day expiration.

The member-facing interface must disclose the 30-day chat retention and the possibility of separate support-ticket retention.

This remains part of the Draft M0 package until the complete M0 architecture is owner-approved and frozen.
