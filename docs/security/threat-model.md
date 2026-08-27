# Threat Model

## Assets

- provider/API keys;
- ORAS member identity/state;
- member-only knowledge;
- Fluent Support data;
- ORAS policies/knowledge integrity;
- API budget;
- WordPress admin privileges;
- conversation content;
- source integrity.

## Threats and controls

### T1 — Anonymous token abuse
Controls: member-only endpoint, server-side authorization, quotas, domain restriction.

### T2 — Member uses ORAS as general AI
Controls: domain guard each turn, concise refusal, usage accounting, abuse thresholds.

### T3 — Member prompt injection
Controls: policy outside model, fixed tool registry, server authorization, argument validation.

### T4 — Website-content prompt injection
Controls: retrieved content labeled untrusted evidence, scripts/forms stripped, no secret-bearing tools, source exclusion/review.

### T5 — Member-only knowledge leakage
Controls: retrieval visibility filter before model; no public AI endpoint; authorization tests.

### T6 — API-key exposure
Controls: server-only secrets, redaction, no client localization, secret scanning.

### T7 — SSRF
Controls: no arbitrary URL fetches, same-site/allowlisted endpoints, URL validation.

### T8 — Cost amplification
Controls: prompt/output/tool caps, timeouts, retry budget, quotas, caching.

### T9 — Hallucinated ORAS policy
Controls: ORAS evidence requirement, source precedence, no-evidence escalation, regression corpus.

### T10 — Stale live fact
Controls: static/live/mixed separation, deterministic source rules, live connector precedence.

### T11 — Ticket spam
Controls: member-only support via AI, ticket confirmation, rate/escalation limits, duplicate detection candidate.

### T12 — Privileged-action manipulation
Controls: no privileged tools unless explicitly implemented, confirmation, WordPress capability checks.

### T13 — Stored XSS
Controls: sanitize on write, escape on render, no raw model HTML.

### T14 — Privacy overcollection
Controls: context minimization, retention policy, least-field tool projections, privacy tests.
