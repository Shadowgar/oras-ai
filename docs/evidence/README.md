# Evidence Policy

Architecture and milestone acceptance rely on retained evidence rather than memory.

Evidence may include:
- automated test output;
- screenshots;
- scan summaries;
- connector fixtures;
- security verification;
- model evaluation results;
- cost/latency measurements;
- owner approval records;
- backup/rollback tests.

## Rules

- Evidence is versioned with the configuration/milestone it proves.
- Screenshots can prove UI state but do not replace authorization/security tests.
- Model-evaluation evidence records model/configuration and dataset version.
- Secrets and unnecessary member data are redacted.
- M0 owner approval is recorded under `evidence/m0-architecture/`.
