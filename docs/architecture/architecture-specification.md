# Complete Architecture Specification

## 1. Scope

ORAS AI is a WordPress-hosted, member-facing assistant providing controlled conversational access to ORAS knowledge, live ORAS systems, astronomy knowledge, current sky/ephemeris data, astronomy-relevant weather, and Fluent Support escalation.

### In scope

- authenticated member chat;
- ORAS website/organization questions;
- general astronomy education;
- current observing and night-sky planning;
- live events, passes/products, and member-state lookups;
- source-linked answers;
- feedback and human escalation;
- automated website knowledge ingestion and synchronization;
- cost, abuse, privacy, security, and audit controls.

### Out of scope for initial production

- anonymous/public AI chat;
- arbitrary general web browsing;
- unrelated writing, coding, shopping, sports, life advice, or general-purpose AI use;
- autonomous purchases or payment handling;
- autonomous policy creation;
- autonomous promotion of support replies into approved knowledge;
- direct unrestricted database access by the model;
- voice/realtime audio and native mobile apps.

## 2. Architectural style

The initial system is a **modular WordPress plugin** integrated with existing ORAS plugins and external APIs. Internal modules remain explicit even if packaged in one plugin:

1. identity and authorization;
2. domain guard;
3. conversation orchestration;
4. knowledge ingestion/synchronization;
5. retrieval/grounding;
6. live ORAS connectors;
7. astronomy/weather connectors;
8. Fluent Support bridge;
9. usage/cost control;
10. audit/observability;
11. admin configuration.

Extraction to external services requires a later ADR.

## 3. Identity boundary

A production chat request requires an authenticated WordPress user with **any active ORAS membership level**. WordPress administrators are also allowed for administration/testing even when they do not hold an active membership. WordPress establishes identity; Paid Memberships Pro establishes active membership state. The server checks authorization on every request. Browser-supplied identity is never authoritative.

## 4. Domain boundary

A request is permitted only when reasonably related to ORAS/ORAS.org or astronomy. Domain restriction applies to every turn and cannot be disabled by user prompting.

Obvious off-topic prompts should be rejected before the expensive answer workflow. Ambiguous prompts may use a low-cost classification step.

## 5. Knowledge classes

### Stable ORAS knowledge
Durable rules, descriptions, directions, benefits, facility information, and similar content. Stored with provenance, visibility, source hash, sync state, and lifecycle.

### Live ORAS data
Event dates, prices, availability, member status, order/account state, and other changing values. Queried at answer time.

### Mixed sources
Pages containing both stable information and changing values. Stable content is extracted; dynamic facts remain live-only.

### General astronomy knowledge
Non-current astronomy education may use model knowledge. Current positions, visibility, Moon state, and weather must use current data/calculation.

## 6. Source precedence

For a given fact:

1. current structured ORAS transaction/state system;
2. current approved ORAS policy/rule;
3. current synchronized ORAS website knowledge;
4. current authoritative astronomy/weather calculation/provider;
5. general model astronomy knowledge;
6. no answer / escalation.

Lower-ranked evidence never overrides higher-ranked authority merely because its wording sounds confident.

## 7. Request flow

1. authentication/membership check;
2. rate and abuse check;
3. input size/normalization;
4. domain guard;
5. intent/evidence planning;
6. knowledge retrieval and/or live tool calls;
7. answer generation under source-precedence rules;
8. source/action rendering;
9. usage/audit recording.

When an ORAS/support answer cannot be established, the assistant offers Fluent Support escalation.

## 8. Astronomy behavior

General educational questions may use model knowledge. Current sky questions require current astronomy data. Observing-night recommendations may combine ORAS access/event constraints, Observer Pass state, twilight, Moon, planets/targets, weather, and astronomy-relevant seeing/transparency where available.

Forecast-based recommendations state uncertainty and forecast time.

## 9. Support and feedback

Fluent Support is the human-support system of record. ORAS AI performs intake, topic classification, context gathering, summarization, routing, confirmation, and ticket creation. It does not silently open tickets.

Feedback, bug reports, suggestions, and complaints may use the same integration.

## 10. Transactions

The assistant may recommend an Observer Pass or event ticket and provide a canonical ORAS purchase link. Checkout and payment remain normal WooCommerce/ORAS workflows.

## 11. Privacy

Only minimum request-relevant member context is sent externally. Passwords, secrets, payment-card data, and unrelated profile/support data are excluded. Conversation retention is configurable and must be decided before member production release.

## 12. Cost boundary

Required controls include per-member quotas, burst throttling, input/output limits, changed-source-only scanning, deterministic classification before AI, bounded retrieval, caching, usage accounting, and fail-closed budget behavior.

## 13. Production gate

The assistant is not production-ready until security, authorization, domain restriction, retrieval grounding, source precedence, rate limiting, failure behavior, and privacy controls are proven by milestone evidence.
