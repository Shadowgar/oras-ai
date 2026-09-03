# M3 Retrieval, Security, and Cost Closure Verification

- **Milestone:** M3 — Retrieval / Security / Cost Boundary
- **Status:** COMPLETE
- **Verification date:** 2026-09-03
- **Branch:** `m3/retrieval-security-cost`
- **M3 closure commit:** `5137eef54a7f9ddff281a499631fe245936c13ae`
- **Commit message:** `Qualify M3 retrieval security and cost boundary`
- **Plugin version:** `0.2.1`

The exact closure commit above is the reproducible M3 milestone identifier.

## Implementation commits

- Task 1 — bounded source-linked retrieval: `bb5b34cfd4b787cb0483f621b87204973722fa2c`
- Task 2 — member request authorization: `8541c4d0c40f629351e93f490188ba1cc89b5a5b`
- Task 3 — domain and capability guardrails: `8b76c6b8420a28664a14b943466c8cbe6b4ca0e8`
- Task 4 — usage and cost limits: `d42e84bc99536c55032b7fa5683ecb1689cdb035`
- Task 5 — grounded answer orchestration: `233c11728af8247b0c91a4a4e261c2ecfea77893`

## Quality verification

Commands:

```bash
npm run lint:php
npm run lint:js
npm test
npm run quality
git diff --check
```

Result: **PASS**

- PHP syntax lint: **PASS — 71 PHP files passed lint**.
- JavaScript validation: **PASS — `node --check assets/scanner.js` completed successfully**.
- Automated tests: **PASS — 221 tests passed, 0 failed**.
- Aggregate quality command: **PASS — exit code 0**.
- Patch whitespace validation: **PASS — no output**.

## Frozen M3 blocker verification

1. [x] **Source-linked retrieval — PASS.** `RET-001`–`RET-004`, `AUTH-003`, `NFR-REL-004`, and `NFR-PERF-003` are enforced by the provider-independent WordPress retriever, evidence packet/value objects, and grounded orchestration. Tests verify approved/active-only eligibility, visibility before ranking, source IDs/URLs, server-derived citations, and bounded evidence.
2. [x] **Fact-level source precedence — PASS.** `RET-005`, `RET-007`, `RET-008`, and ADR-0009 are enforced by the source-precedence resolver and context assembler. Tests verify Live ORAS state over synchronized knowledge, policy-intent gating, and historical/current gating.
3. [x] **Authentication/member authorization — PASS.** `AUTH-001`–`AUTH-005`, ADR-0003, and ADR-0013 are enforced at the authenticated WordPress AJAX gateway. Tests cover anonymous denial, Boolean active-membership eligibility, administrator allowance, PMPro-missing fail-closed behavior, server-derived identity/visibility, nonce enforcement, cross-user claim rejection, and the global kill switch.
4. [x] **Domain guard — PASS.** `DOMAIN-001`–`DOMAIN-006`, ADR-0004, ADR-0005, and `NFR-OBS-003` are enforced rule-first on every request, with a bounded classifier used only for ambiguous input. ORAS, astronomy, crossover, off-topic, override-attempt, and independent-follow-up behaviors are covered.
5. [x] **Prompt-injection/security tests — PASS.** `NFR-SEC-001`–`NFR-SEC-007` and ADR-0013 are covered by application-enforced capability/argument/depth controls, safe URL policy, untrusted evidence roles, separated grounded context, safe output normalization, protected admin changes, and secret/content-minimized logging.
6. [x] **Quota/burst/input/output limits — PASS.** `COST-001`–`COST-004`, `COST-006`, ADR-0016, `NFR-REL-005`, and `NFR-PERF-003` are covered by pre-provider admission, daily/monthly quotas, rolling burst limits, input/output/context bounds, execution timeout, no runtime tools, and bounded capability depth.
7. [x] **Usage/audit/cost baseline — PASS.** `COST-005`, `COST-006`, the M3 quota slice of `ADM-005`, `NFR-SEC-006`, `NFR-SEC-007`, `NFR-OBS-003`, and ADR-0019 are covered by metadata-only usage records, rejection/domain counters, admin aggregate visibility, audited configuration changes, reservation/reconciliation, warning, and hard stop behavior.
8. [x] **No-evidence ORAS behavior — PASS.** `RET-006` and threat T9 are enforced before answer-provider execution. An ORAS factual request without admitted authority returns exactly `I couldn't establish that from the current ORAS information.` and makes zero answer-provider calls.

## Retrieval qualification

- Normal retrieval admits only active Approved artifacts. Draft, Needs Review, Retired, missing/error/excluded-source, and disallowed-visibility records are filtered before ranking.
- Ranking is deterministic: query matches are weighted title, answer, category, then source; ties use authority and artifact ID.
- Evidence retains artifact/source IDs, canonical URL, source type, authority, classification, visibility, lifecycle, hashes, modification/sync freshness, historical status, fact key, and untrusted-content role.
- Bounds are 500 candidates, top-K 5, 2,000 evidence characters per item, 6,000 evidence characters total, and a 16,000-character encoded provider-context envelope.
- Policies & Rules evidence is admitted only for policy/security/privacy/legal intent. Historical event evidence is admitted only for historical intent and cannot establish current facts.
- Fact selection follows Live ORAS state, approved ORAS policy, synchronized ORAS knowledge, current astronomy/weather, general model astronomy, then no-answer/escalation.
- Empty retrieval is explicit. Current ORAS facts without qualified Live ORAS authority fail closed.
- The first M3 implementation uses local WordPress/database keyword search. It has no vector database, embedding, external search, or RAG-framework dependency.

## Authorization qualification

- The production request hook is authenticated-only; anonymous requests stop before nonce, membership, retrieval, or provider work.
- Identity comes from `get_current_user_id()`. Browser-supplied identity, membership, administrator, or visibility claims cannot elevate access.
- The action-specific nonce is checked before membership/provider work. Normal users require any active PMPro membership; administrators are allowed separately.
- Missing PMPro fails closed for normal members. M3 uses only the Boolean active-membership result; member-specific PMPro answer context remains M5.
- Allowed visibility is derived server-side as Public + Members for active members and Public + Members + Admin for administrators, then enforced before ranking and again during context assembly.
- The global kill switch blocks member and administrator answer execution without disabling the site or administrative scanner work.

## Domain and security qualification

- Clear ORAS support and stable astronomy are allowed; legitimate observing crossover is allowed; obvious schoolwork, sports, coding, and other off-topic requests receive a concise refusal before the answer provider.
- Ambiguous input alone invokes the constrained domain classifier. Malformed/provider-failed classification fails closed. Every request is independently rechecked.
- Member and retrieved prompt-injection content cannot alter system policy, identity, visibility, precedence, quota, tools, URLs, or secrets.
- The runtime answer path exposes no tools. The capability registry rejects invented identifiers, malformed arguments, and excess depth. The URL policy permits only explicitly configured HTTPS hosts and rejects arbitrary, credentialed, non-HTTPS, private, loopback, and localhost destinations without fetching.
- Domain observability stores bounded outcome counts only. It does not retain prompt/evidence text, provider errors, or secrets.
- Server source references are built from admitted evidence. Model-provided links cannot become citations. Model output is normalized to plain text, and provider failures expose no raw payload, API key, prompt, or stack trace.

The frozen acceptance catalog and implementation tests number the same six domain behaviors in different orders: the catalog starts with astronomy and ORAS-event examples, while implementation labels start with ORAS and astronomy classes. Qualification maps the behaviors rather than changing requirement meaning.

## Cost and execution qualification

Frozen defaults:

- 25 allowed AI questions per member per UTC day;
- 150 allowed AI questions per member per UTC month;
- 5 requests per rolling minute per member;
- site-wide warning at $10 per UTC month;
- site-wide hard stop at $20 per UTC month.

M3 implementation defaults selected where the frozen documents were silent:

- 4,000 member-input characters;
- 800 output tokens;
- 30-second answer-provider timeout;
- UTC daily/monthly quota boundaries;
- 16,000-character bounded provider-context envelope.

The approved accounting policy uses provider-reported token usage, a local auditable per-model price table with separate input/output rates, conservative pre-call reservation, and actual post-call reconciliation. It performs no live pricing lookup. Missing model pricing fails closed. Open reservations count toward the hard stop; the warning is visible and non-blocking; the hard stop blocks before paid answer-provider execution. Valid actual usage replaces the reservation, duplicate reconciliation cannot double-charge, definite no-dispatch failures release the reservation, and uncertain post-dispatch failures settle the reserved maximum. The ledger retains metadata without question, evidence, provider response, or prompt content and prunes records older than 12 months. Cost configuration requires `manage_options`, an action-specific nonce, strict validation, non-autoloaded storage, and semantic audit records.

The catalog's `AT-COST-004` behavior is tool-recursion capping. The implementation's cost suite uses that label for missing-pricing fail-closed behavior, while the catalog behavior is separately proven by the capability-depth test and the no-tools answer runtime. Qualification maps the documented behavior and preserves both existing labels.

## Grounded orchestration qualification

The effective runtime order is:

1. authenticate with server-derived WordPress identity;
2. validate the action-specific nonce and request;
3. establish Boolean member/administrator eligibility and server-derived visibility, then enforce the global kill switch;
4. apply quota, burst, input, pricing, and site-budget admission and reserve maximum paid usage;
5. apply the domain guard;
6. retrieve evidence under authorized visibility;
7. apply fact-level precedence;
8. assemble bounded grounded context with system policy, member input, and evidence kept separate;
9. invoke the answer-provider adapter only when the admitted path requires it;
10. reconcile actual usage, release definite no-call reservations, or conservatively settle uncertain post-dispatch usage.

Qualified outcomes:

- **ORAS factual request with evidence:** only admitted ORAS evidence reaches the provider; returned references are structured and server-derived.
- **ORAS factual request without evidence:** the deterministic no-evidence sentence is returned with zero answer-provider calls.
- **Stable general astronomy:** qualified model knowledge may answer without ORAS retrieval.
- **Current astronomy/night-sky/weather before M6:** deterministic current-data-unavailable response; model memory is not used as current authority.
- **Crossover:** ORAS statements use admitted evidence; stable astronomy may use model knowledge; a missing ORAS component is disclosed and never fabricated.
- **Off-topic:** concise refusal; deterministic cases avoid retrieval and answer-provider execution.
- **Provider failure:** safe structured failure with conservative accounting and without raw provider/API/prompt detail.

## Acceptance-test and security corpus mapping

- [x] **AT-AUTH-001–004:** anonymous denial, ineligible denial, admin-only visibility filtering, and browser identity non-impersonation all pass. Test labels and catalog numbering differ for the middle cases, so behaviors are mapped directly.
- [x] **AT-DOMAIN-001–006:** stable astronomy, ORAS question, history-paper refusal, sports refusal, observing-weather crossover, and prompt override refusal pass; independent follow-up rechecking is also covered.
- [x] **AT-RET-001–004:** authoritative source link, Live-over-static fact resolution, deterministic no-evidence behavior, and pre-model restricted-source filtering pass.
- [x] **AT-COST-001–004:** daily/monthly quota, rolling burst, oversized-input rejection, and bounded tool depth/no-tools runtime pass. Missing-price, output, timeout, reservation, warning, hard-stop, and reconciliation cases add coverage.
- [x] **AT-SEC-001–005 as applicable to M3:** API-key non-exposure, malicious evidence isolation, unsafe URL rejection, safe model-output normalization, and capability/CSRF-protected admin settings pass. Ticket-output handling remains M7 because no ticket workflow exists in M3.

The reproducible corpus includes malicious member prompts, malicious retrieved evidence, fake identity/visibility escalation, invented capabilities, unsafe URLs, no-evidence hallucination pressure, conflicting static/Live facts, historical/current confusion, and quota/rate/cost bypass attempts. Existing automated tests provide this evidence; no separate evaluation framework was introduced.

## Closure clarifications and limitations

- `NFR-REL-004`: M2 supplied canonical active-artifact eligibility; M3 qualifies enforcement in actual retrieval.
- `NFR-REL-005`: M3 owns bounded/fail-closed answer-provider execution. No runtime tools are enabled; future connector retries remain with their owning milestones.
- `NFR-PERF-003`: M3 qualifies bounded candidates, top-K, per-item evidence, total evidence, and provider context.
- PMPro: M3 owns only Boolean active-membership eligibility. Member-specific PMPro answer context remains M5 `LIVE-003` work.
- **Ambiguous-domain classifier accounting:** frozen documents require staged low-cost classification and a usage/audit baseline but do not explicitly assign classifier token spend to the Task 4 answer-execution ledger or the same $10/$20 reconciliation path. Current accounting covers answer-provider execution only. This ambiguity does not fail a named M3 acceptance behavior and remains a documented clarification item; no live pricing or speculative accounting was added.
- **Crossover status:** the frozen documents define no partial-success status. Returning `success` for the stable astronomy portion while prefixing the exact ORAS no-evidence disclosure satisfies the no-fabrication behavior; no new status was invented.
- Automated qualification ran in the repository's WordPress test harness. Although WP-CLI is installed, this repository path is not a WordPress installation and has no configured browser/live WordPress runtime or ORAS production-like dataset. No ORAS.org data was imported. Browser, staging, and production behavior/data validation remain later deployment evidence and are not claimed here.

## Deferred M4+ scope

M3 closure does not implement member chat UI, conversation persistence/30-day expiry/disclosure, accessibility/progress UX, admin test console, live Events Calendar/WooCommerce/PMPro answer-context connectors, astronomy/weather providers or recommendations, Fluent Support/ticket handling, member-aware commerce actions, or release/security/recovery deployment gates. M4 remains the next milestone and is not marked started or complete.

The plugin remains version `0.2.1`. No ZIP, checksum, release artifact, release tag, version bump, production-data import, or M4+ implementation was created.
