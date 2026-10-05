# AI Evaluation Plan

## Versioned corpus

Each case records:
- prompt;
- expected domain result;
- expected source/tool plan;
- required facts;
- prohibited claims;
- expected citation/action;
- escalation behavior.

## Metrics

- domain precision/recall;
- ORAS factual grounding;
- live-data correctness;
- mixed-source dynamic leakage;
- retrieval relevance/top-K recall;
- unsupported ORAS claims;
- tool correctness;
- input/output/tool cost;
- end-to-end latency.

## Model qualification

A new model/model snapshot replaces an existing one only after the corpus is run and material regressions are reviewed.

## High-risk human-review set

Maintain stricter tests for:
- access rules;
- safety;
- equipment authorization;
- payments/refunds;
- event dates;
- membership eligibility.

These require higher confidence than ordinary astronomy explanation.

## M9 retained release evaluation v1

**PROPOSED RELEASE THRESHOLDS — OWNER ACCEPTANCE PENDING**

The [core corpus](release-evaluation/core-v1.json) minimally instantiates the
existing dimensions above. It contains 71 cases, including two deterministic support-state cases, six direct domain
classifier calls, six SUP-004 summaries and four bounded source classification /
mixed extraction cases. `D-malformed` is an injected malformed-response contract
case, **fixture only**; the two support-state cases are also fixture only, so 68 cases are live eligible. Optional stress corpora are
not part of this task. The fixture sky/weather instant is fixed, not today's sky.
All member identities, provider facts, guides and scanner content are synthetic;
the guide text does not establish real ORAS policy.

Each case retains a requirement reference, domain/workflow, evidence profile,
required facts, forbidden claims, refusal/uncertainty expectation, source behavior,
side-effect proposal expectation and qualitative guidance. Natural-language
required facts are semantic review criteria, not exact golden answers.
`assert_contains` is reserved for deterministic authoritative rendering (price,
stock, registration and membership). Independent scripted provider outputs are
in `tests/fixtures/release-evaluation-responses.json`; the grader never generates
answers from expected outcomes.

### Deterministic contracts and semantic hard failures

Authentication, eligibility, nonce checks, identity, visibility/privacy, source
precedence, canonical URLs, provider facts, commerce boundaries, confirmation,
quota/cost hard stop and malformed-output containment remain **100% pass/fail**.
The normal regression suite is mandatory. These protections are not qualitative
scores or percentage tolerances. The synthetic authorizer/retriever/provider
adapters in this corpus isolate model synthesis; they do not re-qualify installed
PMPro, retrieval ranking, WordPress authentication or real provider availability.

Any observed fabricated ORAS policy/current astronomy/weather, authoritative
fact contradiction, wrong member state, IDOR/private-data leak, arbitrary unsafe
handoff, invented price/purchase/payment, unconfirmed side effect, leaked secret /
provider internals, or failure to refuse off-topic work blocks qualification.
Local rules check explicit states, exact authoritative renderings, source hosts,
specified forbidden substrings, support summary bounds/distinctness/numbers,
structured outputs, known usage and truncation. **Passing those rules is not
proof that all semantic hard failures are absent.** Human review must examine
raw redacted model text, final guarded text and supplied evidence, including all
high-risk cases; hidden contradictions and paraphrased fabrication cannot be
cleared by a substring grader. Record whether server guards replaced model prose.
A harmful raw contradiction remains a model-quality concern even when final
member output is corrected; any harmful final output is a hard blocker.

### Proposed rubric and thresholds

Use six 1–5 dimensions: faithfulness, relevance, completeness, clarity,
calibrated uncertainty and concision. `1` is unusable/misleading, `3` needs a
material edit, `5` is correct and readily usable; `2`/`4` are intermediate.
An applicable uncertainty score evaluates calibration, including avoiding
unnecessary uncertainty in basic explanations. Reviewer records an evidence note
and each score; missing review stays **null**, never zero or pass. No model judge
is used. Human semantic hard-gate decisions are separate from these scores.

| Gate | Proposed acceptance |
| --- | --- |
| Deterministic security/privacy/fact/link/payment/confirmation contracts | 100%; no exceptions from averages |
| Semantic hard failures in final output | 0 across every reviewed case |
| Human coverage | All live cases reviewed; all eight categories and all specialized workflows present |
| Case quality pass | All dimensions at least 3 and case mean at least 4 |
| Overall quality | At least 95% of qualitatively eligible cases pass; overall mean and median at least 4.0 |
| Category quality | Mean at least 3.8 and at least 90% pass in each category; small-category rounding uses ceiling |
| Support summary | 100% validation/identity/original-question separation; useful, distinct summary per rubric |
| Domain classifier | 100% five live domain cases correct within 128 output tokens; malformed fault fails closed |
| Scanner | 100% valid schema and safe stable/dynamic separation; no required content truncated at 12,000 |
| Answer cap | No materially truncated required content at 800; no cap increase authorized |
| Reliability | 100% injected fault containment; at least 95% eligible live cases complete without provider/runtime failure; 0 invalid outputs exposed as valid |
| Accounting | All dispatched calls accounted by shared Task 1 ledger; usage unknown is a blocker, not free usage |
| Latency | Report provider median/max and p95 for at least 20 provider calls, plus failures/timeouts, local processing and harness time; no new latency SLO |
| Cost | Report known provider usage, qualified local cost/case, unknown conservative exposure, and actual $10/$20 headroom/projections; no guessed public rate |

A model configuration cannot qualify from fixtures. The owner must approve these
thresholds and evaluate measured results before choosing a configuration. The
rubric may be applied manually to retained JSON evidence; there is no score
importer, automatic acceptance or elaborate judge platform.

### Commands, scope and reproducibility

```bash
npm run evaluate:release
npm run evaluate:release -- --output=/tmp/oras-ai-release-review-NEW
# Separate explicit paid opt-in, only after authorized disposable setup:
npm run evaluate:release -- --live --config=/private/local-evaluation.json --output=/private/evaluation-NEW
```

Default mode is fixture only and makes zero Internet requests. `npm test` runs
harness integrity tests with fixtures; `npm run quality` never runs live mode.
Evidence directories must be new, are private, and contain `report.json` plus a
checkpoint `events.jsonl`. The report retains source commit and content
fingerprint (including uncommitted tooling), corpus version/hash, case IDs,
requested model/reasoning/caps/input hash, synthetic or provider-reported usage,
known/unknown local accounting, timing, per-call provider failures, per-case unsuccessful or
rejected outcomes (not inferred malformed output per upstream call), rule results, raw redacted model output,
final output and null human scores. Failure cases are retained. Never publish
private live evidence without review; no key or unnecessary member data belongs
in the corpus or report.

The implementation uses the released gateway, domain guard, assembler, answer
orchestrator, answer/summary/classifier/scanner adapters and shared Task 1 paid
transport/ledger. Synthetic normalized live facts and fixed astronomy/weather
providers replace external facts; no production scanner or astronomy/weather
provider is contacted. The six summary cases evaluate the real summary service; `U-proposal` and
`U-uncertain` remain summaries of those user issues. Two separate retained
fixture-only cases, `T-proposal-state` and `T-uncertain-state`, exercise released
proposal, pending and confirmation services plus the actual `chat.js` support
renderer. They retain member-facing preview/buttons and uncertain/no-retry
wording, prove zero mock ticket attempts before confirmation, and use one
explicitly confirmed memory-only uncertain adapter result to prove no duplicate
retry. Their synthetic conversations/pending records exist only in the in-memory
test bootstrap. Existing full support/frontend regressions remain mandatory.
No real conversation, ticket, order or payment is created.

Live mode requires all of the following already authorized local configuration:

- A mode-0600 JSON config containing `opt_in` equal to
  `I_AUTHORIZE_DISPOSABLE_PAID_EVALUATION`, exact `bootstrap` path to disposable
  `wp-load.php`, `pricing_qualified: true`, explicit unique `case_ids`, matching
  unique `user_ids`, integer `max_calls` (1–100) and `max_microdollars` (1–2,000,000).
- An owner-created `.oras-ai-disposable-evaluation` file beside that bootstrap,
  containing exactly `ORAS_AI_SYNTHETIC_DISPOSABLE_ONLY`. This is an explicit
  authorization marker, not automatic proof of deployment identity. The exact
  path must be verified disposable before creating it. The command never creates
  that marker or launches an environment. Cron is disabled and external HTTP is
  blocked while loading WordPress. Unrelated installed-plugin code still runs
  during bootstrap: the later strict endpoint/mail hooks do not prove suppression
  of every unrelated local-request/mail startup hook. This requires an already
  authorized disposable bootstrap; native paid operation is not yet qualified.
  Native local/development environment and
  localhost/127.0.0.1/`.test` site-host checks follow. Never mark production.
- The exact current checkout runtime, existing enabled test-site member AI,
  unchanged Luna/Low, 800-token answer cap, $10/$20 controls, existing key and
  qualified saved Luna prices. No key is accepted in config/command/chat.
- Existing synthetic accounts whose logins begin `oras-ai-eval-` and whose
  `oras_ai_evaluation_synthetic` user meta equals `1`. One distinct identity per
  selected case avoids exceeding the normal per-member quota/burst during a
  corpus; ordinary production admission still applies. The tool creates no users
  and never resets quota/spend. Retried/batched runs retain accumulated spend.

Before any paid request, the plan sums the Task 1 maximum serialized-input-byte
plus framing bound and frozen output cap for every possible call (including an
additional classifier per answer). Input and output cost components each round
up independently, exactly as in the Task 1 ledger. It requires this maximum to fit
explicit call/$2 run limits and existing shared-ledger $20 headroom. The saved
ledger then performs ordinary durable reservation, dispatch and settlement.
Native accounting is never restored/deleted after success or failure. A blocked
or incomplete batch cannot qualify the full corpus. Choose explicitly bounded,
nonoverlapping case batches if the conservative full-core plan exceeds the run
ceiling; do not raise production controls. No repeated sampling or retry loop.

Only then does a read-only `/v1/models` request check actual account access, with
only allowlisted IDs retained. The native network gate permits that one endpoint
and bounded `/v1/responses` requests; unrelated HTTP/mail is blocked. Provider
errors remain bounded and per-case checkpoints survive later failures. Model-list
access alone is not successful Responses or reasoning qualification.

The runtime allowlist remains `gpt-5.6-luna`, `gpt-5.6-terra`, `gpt-5.6-sol`.
This first runner targets the intended **Luna/Low** configuration only. Official
[Luna documentation](https://developers.openai.com/api/docs/models/gpt-5.6-luna)
supports Low and Medium with Responses, but all four product adapters hard-code
Low. **Luna/Medium is blocked by the current runtime configuration interface**;
this evidence task does not change it. A comparison configuration is optional
and none is justified without any measured baseline. No GPT-6 runtime model is
added. [API model listing](https://developers.openai.com/api/reference/resources/models/methods/list)
is account-specific and does not replace the paid pipeline check.
