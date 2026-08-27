# M0 Architecture Owner Approval

**Status:** APPROVED  
**Approval date:** 2026-08-27  
**Architecture freeze:** ACTIVE

## Approval statement

The owner explicitly approved and froze M0 after reviewing the product scope and resolving the M0 owner decisions.

The approved M0 baseline defines ORAS AI as:

- a member-only AI assistant for active ORAS members, with WordPress administrator access for testing/administration;
- limited to ORAS/website/customer-support and astronomy/observing domains;
- protected by member quotas, burst controls, and site-wide OpenAI budget safeguards;
- backed by automatic, rule-first ORAS website knowledge ingestion;
- able to distinguish stable, live, mixed, historical, ignored, and review-required content;
- integrated with WooCommerce, The Events Calendar, Paid Memberships Pro, and Fluent Support through normalized application boundaries;
- able to answer general astronomy questions and later current observing/weather questions through qualified astronomy/weather connectors;
- prohibited from anonymous/public AI access at launch;
- prohibited from unrelated general-purpose AI use;
- prohibited from autonomous purchasing/payment;
- governed by source precedence, least-privilege authorization, prompt-injection defenses, privacy/retention controls, cost controls, acceptance testing, and milestone gates.

## Owner decisions resolved before freeze

1. Member eligibility
2. Usage limits and site-wide AI budget
3. Conversation and usage retention
4. Speaker biography indexing
5. Privacy/security policy searchability
6. Historical event-page indexing
7. Fluent Support routing architecture
8. Astronomy provider selection deferred to M6
9. Weather provider selection deferred to M6

## Governance

After this approval, material architecture changes require a new or superseding ADR and corresponding updates to requirements, traceability, and milestone acceptance criteria.

This approval authorizes implementation to proceed against the frozen M0 baseline.
