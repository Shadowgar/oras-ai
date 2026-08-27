# M0 Owner Review Checklist

**M0 Status:** APPROVED AND FROZEN — 2026-08-27

## Product
- [x] AI is member-only at initial release.
- [x] Public anonymous visitors do not receive AI chat.
- [x] Allowed domains are ORAS/website and astronomy only.
- [x] General astronomy questions are allowed.
- [x] Current sky/weather questions require current data.
- [x] Fluent Support is the support system of record.
- [x] AI may recommend/link to passes/tickets but does not complete payment.

## Knowledge
- [x] Rule-first classification is accepted.
- [x] Static/Live/Mixed/Ignore/Review classes are accepted.
- [x] Mixed pages extract durable content while live facts remain live.
- [x] Manual knowledge remains possible but exceptional.
- [x] Source-precedence order is accepted.

## Cost
- [x] Per-member quotas required.
- [x] Burst throttling required.
- [x] Off-topic prefiltering required.
- [x] General web search disabled by default.

## Privacy/security
- [x] Restricted knowledge filtered before model.
- [x] External model receives only needed member context.
- [x] Tickets require confirmation.
- [x] Prompt-injection boundary accepted.
- [x] Conversation retention policy accepted — 30-day chat text retention; 12-month usage/cost metadata retention; Fluent Support ticket retention remains separate.

## Open decisions before M0 acceptance
- [x] Define eligible member levels for AI — **Decision:** any logged-in user with an active ORAS membership is allowed; WordPress administrators are allowed for testing/administration even without an active membership.
- [x] Choose initial usage limits — **Decision:** 25 allowed AI questions per member per day, 150 per member per month, and a 5-request-per-minute burst limit. Site-wide OpenAI spend warning at $10/month and hard stop at $20/month. Obvious locally blocked off-topic requests do not consume the member AI-question quota, but still count toward abuse/rate-limit telemetry.
- [x] Choose conversation retention period — **Decision:** retain AI conversation text for 30 days; retain usage/cost metadata for 12 months without full conversation text; Fluent Support tickets follow the separate ORAS/Fluent Support support-retention policy.
- [x] Decide whether admins may use AI without active membership — **Decision:** yes; WordPress administrators may use ORAS AI for administration/testing without an active membership (resolved with M0 Decision 001).
- [x] Decide whether speaker biographies remain indexed by default — **Decision:** yes. `oras_speaker` records remain indexed as Event/educational knowledge by default unless explicitly excluded. Speaker records are not treated as authoritative Board/organization-role sources unless corroborated by an official ORAS organizational source.
- [x] Decide whether privacy/security policy pages are member-searchable — **Decision:** yes. Public ORAS privacy/security policy pages remain searchable under Policies & Rules, but retrieval should be intent-targeted to privacy, security, data handling, legal/terms, or vulnerability-reporting questions rather than general FAQ retrieval.
- [x] Decide whether old historical event pages remain indexed — **Decision:** yes. Historical event pages remain searchable as historical knowledge, with lower retrieval priority than current event systems and only for clearly past/history/previous-event intents. They must never answer current/upcoming event questions.
- [x] Define Fluent Support routing/mailboxes — **Decision:** use one primary ORAS Support mailbox with AI topic/category tags and configurable WordPress-to-Fluent-Support routing to the appropriate team/agent. No individual person's email/name is hard-coded in prompts or code. Every route falls back to General ORAS Support.
- [x] Confirm astronomy-provider selection is deferred to M6 — **Decision:** yes. M0 freezes the required astronomy capabilities and normalized connector boundary, but the actual provider/library is selected during M6 after evaluating accuracy, reliability, cost, licensing, time-zone correctness, and testability.
- [x] Confirm weather-provider selection is deferred to M6 — **Decision:** yes. M0 freezes the required weather/observing capability contract and normalized connector boundary, while the actual weather provider is selected during M6 after evaluating forecast quality, reliability, cost, licensing, freshness, astronomy relevance, and testability.

## Freeze
- [x] Set README status to Approved.
- [x] Change ADR statuses to Accepted.
- [x] Add acceptance date — **2026-08-27**.
- [x] Record owner approval under `docs/evidence/m0-architecture/`.
- [x] Activate architecture freeze under ADR-0020.
