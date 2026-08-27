# Operations Runbook

## Routine monitoring

Watch:
- connector health;
- scanner failures;
- cost/budget alerts;
- Needs Review backlog;
- unusual domain/rate-limit spikes;
- repeated unanswered ORAS questions.

## Changing the model

1. Record current model/config.
2. Run the versioned evaluation corpus.
3. Compare grounding, domain compliance, tool correctness, cost, and latency.
4. Review regressions.
5. Test with admins.
6. Roll to members with rollback available.

## Changing a connector/provider

Run normalized contract tests. Orchestration behavior should not need rewriting because a provider names fields differently.

## Kill switch

Provide an admin switch that:
- disables member AI requests;
- leaves the ORAS website operational;
- preserves knowledge/configuration;
- displays a normal support/contact fallback.

## Degraded mode examples

- OpenAI unavailable → AI unavailable; ORAS website remains functional.
- Weather unavailable → stable ORAS/general astronomy may continue; observing recommendation states weather unavailable.
- Fluent Support unavailable → normal answers continue; escalation shows fallback.
- Scanner unavailable → existing approved knowledge remains; live systems remain queryable.
