# M0 Decision 007 — Fluent Support Routing Architecture

**Status:** Resolved during M0 review

## Decision

ORAS AI uses **one primary ORAS Support mailbox** for AI-created support requests.

The assistant classifies each confirmed request into an ORAS topic. WordPress configuration maps that topic to Fluent Support tags and, where appropriate, team/agent assignment.

| ORAS AI category | Intended destination |
|---|---|
| Membership | Membership / Treasurer |
| Payments / Observer Passes | Treasurer |
| Observatory Access | Observatory team |
| Facilities | Facilities team |
| Equipment | Equipment / observatory contact |
| Events / Public Nights | Events contact |
| AstroBlast | AstroBlast contact |
| Website / Technical | Webmaster |
| Board / Organization | Board / general contact |
| Unclear / Other | General ORAS Support |

## Rules

- No individual's name or email is hard-coded in the AI prompt or plugin code.
- Administrators update routing configuration when ORAS roles change.
- Every route falls back to **General ORAS Support**.
- A missing/stale route must not silently lose a ticket.
- Ticket creation still requires explicit member confirmation.

Actual Fluent Support agent/team/mailbox IDs are an implementation-time configuration and are not frozen into M0 architecture.

This remains part of the Draft M0 package until the complete M0 architecture is owner-approved and frozen.
