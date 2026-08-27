# Fluent Support Integration

## Decision

Fluent Support is the system of record for human support requests. ORAS AI does not implement a parallel ticket lifecycle.

## Ticket triggers

ORAS AI may create a ticket when:
- an ORAS/member-support question cannot be answered authoritatively;
- the member explicitly asks for human help;
- the member submits feedback, a suggestion, a bug report, or complaint.

## Explicit confirmation

Before ticket creation, show:
- topic/category;
- short summary;
- whether the original question is included;
- intended support destination at a human-readable level.

Only after confirmation is a side-effecting ticket call made.

## Ticket payload

May include:
- authenticated WordPress/member identity;
- ORAS topic;
- concise AI summary;
- original question;
- relevant conversation reference;
- sources searched;
- failure/confidence reason;
- ORAS AI tags/metadata.

Do not dump an entire unrelated conversation by default.

## Routing

Configurable topic mappings:
- Membership
- Observatory
- Observer Passes
- Equipment
- Events/AstroBlast
- Facilities
- Website/Technical
- Payments/Treasurer
- Board/Organization
- Other/fallback

Mappings target capabilities available in the installed Fluent Support version, such as mailbox, tags, team/agent assignment.

## Knowledge-gap loop

A resolved ticket may become a candidate knowledge gap when a question repeats. Human approval is required before support content becomes approved knowledge. A support reply is not automatically ORAS policy.

## Technical mechanism

Use documented Fluent Support REST APIs and/or documented WordPress hooks. Pin and test behavior against the installed Fluent Support version rather than depending directly on undocumented database internals.


## Initial routing architecture

ORAS AI uses **one primary ORAS Support mailbox** as the default support destination.

The AI classifies a confirmed support request into an ORAS topic. WordPress configuration then maps that topic to Fluent Support tags and, when configured, an appropriate team/agent.

Initial topic map:

| ORAS AI topic | Intended routing destination |
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

### Routing principles

- No person's email address or name is hard-coded into the system prompt or implementation.
- Administrators update routing in WordPress when officers, committee chairs, or agents change.
- Every category has a **General ORAS Support** fallback.
- A routing failure must not silently drop the ticket.
- The member sees a human-readable category/summary before confirming ticket creation.
