# ORAS AI Assistant Architecture Package

**Status:** Approved M0 architecture baseline  
**Architecture freeze:** Active — 2026-08-27  
**Prepared:** 2026-08-16
**M0 owner approval:** 2026-08-27  
**Implementation baseline observed:** ORAS AI Assistant WordPress plugin v0.2.1 proof of concept

This package defines the implementation-independent architecture for ORAS AI. It intentionally separates product requirements, architecture, decisions, security, operations, quality, UX, roadmap, and evidence so future implementation can execute an agreed plan instead of making architectural decisions while coding.

## Product intent

ORAS AI is a **members-only assistant** for exactly two permitted domains:

1. ORAS, ORAS.org, membership, facilities, events, observatory use, equipment, policies, services, and member support.
2. Astronomy, observing, astronomical equipment, space science, astrophysics, cosmology, astrophotography, the current night sky, and astronomy-relevant weather.

It is not a general-purpose chatbot. Anonymous/public AI access is disabled for the initial release.

The assistant combines approved ORAS knowledge, live ORAS systems, current astronomy/weather data, general model astronomy knowledge, and Fluent Support escalation.

## Core design rules

- Authenticated member-only AI at launch.
- ORAS/website and astronomy are the only allowed domains.
- WordPress structure is used before AI when the source type already reveals semantics.
- Stable, live, mixed, ignored, and review content are treated differently.
- Every managed knowledge item retains source provenance and freshness.
- Live structured ORAS state outranks static copied text.
- ORAS policy/current facts are not guessed.
- Fluent Support is the system of record for human support.
- Side effects such as support-ticket creation require explicit confirmation.
- Purchases stay in the normal ORAS/WooCommerce checkout flow.
- Cost controls, rate limits, prompt limits, and usage accounting are required.
- Retrieved text is untrusted evidence, never executable instruction.

## Document map

### Architecture
- [Complete architecture specification](architecture/architecture-specification.md)
- [System context](architecture/system-context.md)
- [Component architecture](architecture/component-architecture.md)
- [Logical data model](architecture/data-model.md)
- [Information flow and source precedence](architecture/information-flow.md)
- [Integration boundaries](architecture/integration-boundaries.md)
- [Deployment profile](architecture/deployment-profile.md)
- [Architecture diagrams](architecture/diagrams.md)

### Requirements
- [Product requirements](requirements/product-requirements.md)
- [Functional requirements](requirements/functional-requirements.md)
- [Non-functional requirements](requirements/non-functional-requirements.md)
- [Requirements traceability matrix](requirements/traceability-matrix.md)

### Knowledge
- [Source-of-truth policy](knowledge/source-of-truth-policy.md)
- [Ingestion and synchronization](knowledge/ingestion-and-sync.md)
- [Classification and mixed-source extraction](knowledge/classification-and-mixed-sources.md)
- [Retrieval, citations, and grounding](knowledge/retrieval-and-citations.md)

### Integrations
- [WordPress](integrations/wordpress.md)
- [WooCommerce](integrations/woocommerce.md)
- [Paid Memberships Pro](integrations/paid-memberships-pro.md)
- [The Events Calendar](integrations/events-calendar.md)
- [Fluent Support](integrations/fluent-support.md)
- [OpenAI](integrations/openai.md)
- [Astronomy and weather](integrations/astronomy-weather.md)

### Security, UX, operations, and quality
- [Threat model](security/threat-model.md)
- [Security architecture](security/security-architecture.md)
- [Privacy and retention](security/privacy-and-retention.md)
- [Prompt-injection defense](security/prompt-injection-defense.md)
- [Member assistant UX](ux/member-assistant.md)
- [Escalation and feedback UX](ux/escalation-and-feedback.md)
- [Admin experience](ux/admin-experience.md)
- [Operations runbook](operations/operations-runbook.md)
- [Scanner runbook](operations/scanner-runbook.md)
- [Incident and recovery](operations/incident-and-recovery.md)
- [Cost control](operations/cost-control.md)
- [Test strategy](quality/test-strategy.md)
- [Acceptance test catalog](quality/acceptance-test-catalog.md)
- [Evaluation plan](quality/evaluation-plan.md)

### Plans, decisions, and evidence
- [Roadmap and milestone acceptance](roadmap/milestones.md)
- [Implementation sequence](roadmap/implementation-sequence.md)
- [Repository layout](plans/repository-layout.md)
- [M0 owner review checklist](plans/m0-owner-review-checklist.md)
- [ADR index](adr/README.md)
- [Evidence policy](evidence/README.md)
- [Prototype v0.2.1 evidence](evidence/prototype-v0.2.1-scan.md)
- [Decision log](evidence/decision-log.md)
- [External references](references/external-sources.md)

## Requirement classes

- **RB — Mandatory release blocker:** required for the milestone/release first introducing the capability.
- **RD — Mandatory before real member deployment:** required before enabling the assistant for real members.
- **QT — Qualified operational target:** measured against a pinned environment and representative dataset.
- **DC — Deferred capability:** deliberately outside current release scope.

## M0 acceptance

M0 was owner-approved and frozen on **2026-08-27**. This package is now the design authority for implementation. Material architectural changes require a new or superseding ADR and corresponding traceability updates.
