# Security Architecture

## Application enforcement

These must never depend solely on prompt obedience:
- authentication;
- membership authorization;
- knowledge visibility;
- rate limits;
- allowed tools;
- tool argument validation;
- side-effect confirmation;
- secret handling;
- URL allowlists;
- admin capabilities.

## Tool capability contract

Each tool defines:
- name/purpose;
- authorized contexts;
- input/output schema;
- timeout;
- side-effect flag;
- confirmation rule;
- audit rule.

The model cannot invent capabilities.

## Data minimization

Adapters return only fields needed for the current answer. A membership tool should not return full billing history when only active status is needed.

## Admin protection

Settings changes require capability checks, CSRF protection, sanitization, and audit records. Secrets are not echoed back.

## M3 security gate

Tests must prove:
- anonymous denial;
- cross-user denial;
- member-only retrieval isolation;
- prompt-injection resistance at capability boundary;
- tool authorization;
- API-key non-exposure;
- rate limiting;
- CSRF protection;
- output escaping.
