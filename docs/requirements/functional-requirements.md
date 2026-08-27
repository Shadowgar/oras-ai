# Functional Requirements

## Authentication and authorization
- **AUTH-001 [RB/RD]** Every chat request verifies authenticated WordPress identity server-side.
- **AUTH-002 [RB/RD]** Eligibility is evaluated server-side using configured membership policy.
- **AUTH-003 [RB/RD]** Knowledge visibility is filtered before model retrieval.
- **AUTH-004 [RB]** Admin controls require appropriate WordPress capabilities.
- **AUTH-005 [RD]** Anonymous visitors cannot invoke the production answer endpoint.

## Domain control
- **DOMAIN-001 [RB]** Only ORAS/website or astronomy requests are answered.
- **DOMAIN-002 [RB]** Off-topic requests receive a concise refusal.
- **DOMAIN-003 [QT]** Obvious off-topic requests should be rejected before the primary answer-model call.
- **DOMAIN-004 [RB]** Every follow-up is rechecked.
- **DOMAIN-005 [RB]** User prompt instructions cannot remove the domain boundary.
- **DOMAIN-006 [RB]** Astronomy-relevant weather/technology is allowed when materially connected to observing/astronomy.

## Knowledge ingestion
- **KB-001 [RB]** Published WordPress sources are automatically discoverable.
- **KB-002 [RB]** Sources retain provenance and hashes.
- **KB-003 [RB]** Deterministic rules run before AI classification.
- **KB-004 [RB]** `tribe_events` is live by default.
- **KB-005 [RB]** WooCommerce products are live by default.
- **KB-006 [RB]** Known templates/utility types are excluded by rule.
- **KB-007 [RB]** Ambiguous sources may use structured AI classification.
- **KB-008 [RB]** Mixed sources extract stable knowledge while dynamic facts remain live.
- **KB-009 [RB]** Normal sync does not reclassify unchanged sources.
- **KB-010 [RB]** Removed sources retire/quarantine scanner-managed knowledge.
- **KB-011 [RB]** Manual knowledge is never silently overwritten by scanner maintenance.
- **KB-012 [RB]** Auto-approval requires safe source class plus configured validation/confidence.
- **KB-013 [RB]** Lifecycle includes approved, needs-review, draft/manual, and retired.
- **KB-014 [RB]** Current/live values are not stored as durable authoritative facts.
- **KB-015 [RB]** `oras_speaker` records are indexed as Event/educational knowledge by default unless explicitly excluded; they do not independently establish current Board/officer/committee roles.

## Retrieval and grounding
- **RET-001 [RB]** ORAS factual answers retrieve approved ORAS evidence/live data before general model knowledge.
- **RET-002 [RB]** Retrieval enforces visibility before evidence reaches the model.
- **RET-003 [RB]** Evidence retains source IDs/URLs.
- **RET-004 [RB]** ORAS answers expose source links when useful.
- **RET-005 [RB]** Source precedence is applied per fact.
- **RET-006 [RB]** Insufficient ORAS evidence does not produce fabricated policy.
- **RET-007 [RB]** Public privacy/security/legal policy sources are searchable under Policies & Rules but are retrieved only for materially relevant policy/security/privacy/legal intents.
- **RET-008 [RB]** Historical event pages may remain searchable for past/history intent but are excluded from current/upcoming-event answers unless explicitly used for historical comparison.

## Live ORAS connectors
- **LIVE-001 [RB]** Event dates/schedules query the authoritative event system.
- **LIVE-002 [RB]** Product/pass price/availability queries WooCommerce.
- **LIVE-003 [RB]** Member-specific state queries PMPro/WordPress after authorization.
- **LIVE-004 [RB]** Connector failure yields bounded uncertainty, not stale invention.
- **LIVE-005 [RB]** Tool results expose only necessary fields.

## Astronomy
- **ASTRO-001 [RB]** Non-current general astronomy questions are answered.
- **ASTRO-002 [RB]** Current sky position/visibility uses current data/calculation.
- **ASTRO-003 [RB]** Observing-weather questions use current forecast/provider data.
- **ASTRO-004 [RB]** ORAS observing-night recommendations combine relevant ORAS, sky, Moon/target, and weather inputs when available.
- **ASTRO-005 [RB]** Forecast-derived recommendations state uncertainty/freshness.
- **ASTRO-006 [RB]** Current visibility is never claimed solely from model memory.
- **ASTRO-007 [DC]** Saved member equipment/preferences may later personalize observing plans.

## Fluent Support / feedback
- **SUP-001 [RB]** Fluent Support is the support-ticket system of record.
- **SUP-002 [RB]** The assistant offers escalation when an ORAS/support answer cannot be established.
- **SUP-003 [RB]** Ticket creation requires explicit confirmation.
- **SUP-004 [RB]** Ticket includes original question plus concise AI summary.
- **SUP-005 [RB]** Only relevant conversation/support context is submitted.
- **SUP-006 [RB]** Topic routing is configurable in WordPress and maps ORAS AI categories to Fluent Support tags/team/agent destinations; no individual person's email/name is hard-coded.
- **SUP-007 [RB]** Feedback/suggestions/bugs may use the same bridge.
- **SUP-008 [RB]** Resolved tickets may become candidate knowledge but never auto-approved knowledge.
- **SUP-009 [RD]** Anonymous AI-driven tickets are disabled initially.
- **SUP-010 [RB]** Every Fluent Support routing category has a General ORAS Support fallback so a missing or stale route does not lose a member ticket.

## Actions / commerce
- **ACT-001 [RB]** Assistant may provide validated canonical ORAS links.
- **ACT-002 [RB]** Purchasing remains normal ORAS/WooCommerce checkout.
- **ACT-003 [RB]** Assistant does not autonomously charge/finalize orders.
- **ACT-004 [RB]** AI-initiated side effects require confirmation.

## Usage / cost
- **COST-001 [RB/RD]** Configurable per-member quotas; initial production values are 25 allowed AI questions/day and 150/month per member.
- **COST-002 [RB/RD]** Burst rate limiting; initial production value is 5 requests/minute per member.
- **COST-003 [RB]** Maximum input length.
- **COST-004 [RB]** Maximum response/token budget.
- **COST-005 [RB]** Admin usage visibility.
- **COST-006 [RB]** Budget exhaustion fails closed; initial site-wide warning is $10/month and initial hard stop is $20/month.
- **COST-007 [QT]** Caching/reuse avoids unnecessary repeated external calls.

## UX / admin
- **UX-001 [RB]** UI clearly states ORAS/astronomy scope.
- **UX-002 [RB]** Off-topic refusal is short.
- **UX-003 [RB]** Live vs stable context is distinguishable when material.
- **UX-004 [RB]** Escalation preview appears before confirmation.
- **UX-005 [RB]** Source/action links are clear.
- **UX-006 [RB]** Errors never expose keys/prompts/stack traces.
- **ADM-001 [RB]** Admin can run changed-source sync.
- **ADM-002 [RB]** Admin can rebuild classifications.
- **ADM-003 [RB]** Admin can exclude sources.
- **ADM-004 [RB]** Admin can review mixed/uncertain items.
- **ADM-005 [RB]** Admin can configure routing, quotas, models, and connectors.
- **ADM-006 [RB]** Scanner-managed vs manual knowledge is visible.
