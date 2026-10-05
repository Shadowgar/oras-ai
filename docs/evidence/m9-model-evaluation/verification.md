# M9 Task 3 — Model and evaluation qualification

Date: 2026-10-05. **LIVE MODEL EVALUATION: BLOCKED BY CONFIGURATION.**

**PROPOSED RELEASE THRESHOLDS — OWNER ACCEPTANCE PENDING**  
**MODEL RECOMMENDATION — OWNER DECISION PENDING**

No production configuration is recommended from this fixture-only run. The
intended `gpt-5.6-luna / Low` remains unchanged and **not live-qualified**. This
is an owner-review artifact for the bounded harness/corpus and proposed gates,
not M9 closure, release readiness, packaging or deployment authorization.

## Verified baseline

| Item | Fresh result |
| --- | --- |
| Branch / HEAD | `m9/production-release` / `0f035ad504327d23dd8c09aa40d5afa8e5e37c83` |
| Origin | Fetch succeeded; ahead/behind 0/0 |
| Worktree before task | Clean |
| Plugin/package version | 0.2.1 |
| Baseline quality | 212 PHP files linted; 742 PHP tests; 78 frontend assertions; zero unexpected PHP warnings; quality passed |
| Baseline log | `/tmp/oras-ai-m9-task3-baseline-quality.log` |

Implementation used the requested GPT-6 Astra/High development agent. No runtime
model/default/reasoning/allowlist, configured price, version or release artifact
was changed. Nothing was staged, committed, pushed or tagged.

## Governing requirements and retained coverage

Reviewed the existing [evaluation plan](../../quality/evaluation-plan.md), M9
roadmap gates, functional/nonfunctional requirements, acceptance catalog,
traceability, M3 cost controls and M3–M8 verification evidence. The original
plan already requires domain precision/recall, ORAS grounding, current-data
correctness, mixed-source leakage, retrieval relevance, tool correctness, cost,
latency and stricter high-risk human review. It did **not** freeze numerical
thresholds or a corpus size. This task extends it without changing those goals.

[Core v1](../../quality/release-evaluation/core-v1.json) has **71 cases**, of which
**68 are live eligible** and three are fixture-only: one malformed-output fault and two support-state contracts. Case counts:

| Category | Cases | Included slices |
| --- | ---: | --- |
| ORAS knowledge / scanner | 14 | Membership, facilities, events, volunteers, member services, insufficient policy evidence, injected source; four paragraph-scale scanner sources |
| General astronomy | 8 | Education, eyepieces, cosmology, black holes, M42, seeing/transparency, Moon phases, light year |
| Current astronomy | 6 | Planet, Moon, explicit target, all planets, broad plan, below-horizon pressure |
| Weather / observing | 6 | Current, tonight, active night, weekend, unavailable weather, Moon/weather synthesis |
| Member-aware | 11 | Own state, inactive presupposition, price, availability, event open/full, combined facts, hostile price/URL/payment |
| Support | 8 | Six distinct useful summaries plus actual member-facing proposal/uncertain states, explicit confirmation and no-retry |
| Off-topic / security / classifier | 12 | Refusal, injection, other-user/private-URL requests, invented current data; five live classifier domains and malformed fixture |
| Partial failure / precedence | 6 | One domain unavailable, unsafe URL, ambiguous pass, unknown event availability, missing planet provider, stale/static price conflict |

The 71 cases comprise **53 answer scenarios, 6 summaries, 6 classifier cases,
4 scanner cases and 2 deterministic support-state cases**. All sky/weather/provider facts are synthetic at a fixed
September instant; active-night has a fixed after-dusk instant. No provider
lookup depends on the actual sky or weather. The fixture guide does not assert
real ORAS policy. The Member Hub score provider is deliberately unavailable, so
broad/weekend planning must expose its partial-evidence limitation.

Real released gateway, domain guard, context/precedence, answer orchestration,
answer/summary/classifier/scanner validation and paid transport/ledger execute.
Provider/knowledge/member facts and membership eligibility are injected fixtures;
this does not replace the existing deterministic authorization, retrieval,
connector and support state-machine regression suites. No browser, production
WordPress, production scanner, live AstronomyAPI, live Fluent Support or payment
qualification is claimed. `U-proposal` / `U-uncertain` retain the original summary-service scope. New
`T-proposal-state` / `T-uncertain-state` explicitly retain released proposal,
pending, confirmation and actual `chat.js` renderer behavior. They show the
preview and explicit Create/Cancel actions before any mock ticket attempt, then
an explicitly confirmed memory-only uncertain result with stable status, no
duplicate retry and no Create button. No real ticket or external side effect
occurs. Existing full support/frontend tests also remain mandatory.

## Proposed grading and hard release gates

Deterministic contracts stay **100% pass/fail**. No averaging compensates for
security, privacy, identity, canonical-link, current-fact, price/payment,
confirmation or hard-stop failure. Semantic fabrication/contradiction, provider
secret leakage and nonintercepted off-topic answers also block release, even
with otherwise good scores. The rule grader checks bounded explicit conditions;
its success does not establish that every possible paraphrased hallucination is
absent. Human semantic hard-gate review remains pending for every case.

The [detailed proposal](../../quality/evaluation-plan.md#proposed-rubric-and-thresholds)
uses six 1–5 dimensions (faithfulness, relevance, completeness, clarity,
uncertainty, concision): case mean ≥4 with no dimension <3; ≥95% cases passing;
overall mean/median ≥4; each category mean ≥3.8 and ≥90% cases passing. All cases
must be reviewed and no semantic hard failure is allowed. Specialized schema,
summary fidelity, source separation, cap and fault-containment gates are 100%.
Live completion target is ≥95%; unknown paid usage prevents accounting
qualification. Latency has **no newly invented SLO**: report median/max and p95
only at ≥20 provider calls. These are proposals, not owner-accepted policy.

No judge model, synthetic quality score or automated model acceptance was added.
`required_facts` prose remains a human semantic criterion; only authoritative
server-rendered literals are exact assertions. Human rubric scores stay null.

## Configuration discovery and comparison

Presence-only discovery found no credential in named OpenAI environment
variables, repository `.env`/`.env.local` or local test configuration. The
existing authorized `oras-wp-env` location was verified with its installed
`wp-env install-path`. Only its disposable tests MariaDB was temporarily started;
read-only SQL against `tests-wordpress` found no nonempty saved OpenAI key and
no saved OpenAI model, cost-controls, usage-ledger or enablement option rows.
The tests `wp-config.php` had no OpenAI constant/reference and tests CLI
environment had no OpenAI credential. Home was the local `http://127.0.0.1:8889`.
No WordPress/plugin/cron process or production site was loaded for discovery.
Newly started container/network were removed afterward, preserving volumes and
the original stopped state and unrelated three untracked wp-env entries.

**API account availability: not queried; unknown.** No usable credential or
qualified saved price was available. The API model catalog documents Luna
Responses/Low/Medium support, but does not establish account access. All four
product adapters hard-code Low, so Medium is additionally blocked by the runtime
interface. Terra/Sol are allowlisted but no comparison run was justified; GPT-6
was not introduced.

| Configuration / evidence | Hard-gate result | Quality/category scores | Malformed output | Latency | Tokens/cost | Caps / specialized results |
| --- | --- | --- | --- | --- | --- | --- |
| Luna/Low **fixture pipeline** | 71/71 explicit rules pass; human semantic gates unreviewed | null / null | 1 injected classifier response rejected | Internet latency not measured | Synthetic usage only, not billing | Answer, summary, classifier, scanner request caps preserved |
| Luna/Low **live** | Not measured | Not measured | Not measured | Median/p95/max unavailable | **0 live calls; 0 paid tokens; $0 paid by this task** | Not qualified |
| Luna/Medium | Not executed: native runtime fixed to Low, and credential/pricing missing | Not measured | Not measured | Not measured | 0 calls | Owner/configuration decision required before evaluation |

The full live-eligible set has a conservative ceiling of **121 calls**, **10,097,904
input tokens** (Task 1 serialized-byte/framing upper bounds, not a tokenizer
prediction) and **98,784 output tokens**. Missing qualified rates prevent a dollar
admission estimate. The live command caps a run at 100 calls and $2 additional
exposure, checks site-wide $20 headroom, and retains the native shared ledger;
therefore a full conservative plan must be split into explicit nonoverlapping
bounded batches. No limits are bypassed or changed. Real per-case cost and
projections against $10/$20 are unavailable until live measurement.

## Fixture observations and reproducibility

Command: `npm run evaluate:release -- --output=/tmp/oras-ai-m9-task3-review-round1-fixture`.
Result: **71 cases, zero rule failures, 58 mocked Responses calls, zero live
calls**. One deliberately malformed classifier response was rejected rather
than dropped. All six summary fixtures passed the real strict JSON and
length/distinctness/numeric/metadata validation. Hostile pass/member/event and
stale-price outputs were replaced by authoritative final prose; both raw redacted
model text and final output are retained for review. Current-sky hostile prompts
have scripted provider answers, so they establish no actual model resistance.

| Adapter | Mock calls | Scripted max output usage | Frozen output cap | Scripted headroom |
| --- | ---: | ---: | ---: | ---: |
| Answer | 42 | 40 | 800 | 760 |
| Support summary | 6 | 40 | 160 | 120 |
| Domain classifier | 6 | 40 | 128 | 88 |
| Scanner classification | 4 | 250 | 12,000 | 11,750 |

**These usages and headrooms are synthetic and cannot qualify model output caps.**
Fixture arithmetic reconciled 17,400 input / 3,160 output tokens and 26,880
microdollars using explicit synthetic unit-test rates. Those numbers are not
actual provider cost or performance, are not inserted into native configuration,
and do not yield usage projections. Provider latency is null; local fixture time
is kept separate. Live reporting aggregates actual adapter calls separately from
case workflows so an auxiliary 128-token classifier is never counted as an
800-token answer's headroom.

Corpus SHA-256:
`9b7c7a4bee28a98cbcfd6f1abadd269c1612c16edd4505741c1ed4ac559b7fd3`.
The [compact fixture artifact](fixture-results.json) retains commit, corpus and
source-tree fingerprints, aggregate metrics, all 71 case outcomes and representative
hostile/specialized raw/final rows. Full reproducible case evidence is in the
command's private `/tmp/.../report.json`; it is not duplicated wholesale in Git.
All human/semantic decisions remain null or owner-review-pending.

`npm run evaluate:release -- --live --output=/tmp/oras-ai-m9-task3-live-blocked`
returned **exit 2**, `live_configuration_missing`, without loading WordPress or
making a provider request. The separate live mode requires an explicitly marked
local bootstrap, private opt-in config, existing synthetic users, saved qualified
prices and normal durable accounting. The no-marker test proves arbitrary
bootstrap code is not executed. Native paid live execution itself remains
unverified because its genuine prerequisites are absent. Before bootstrap, the
marker/explicit path, disabled cron and external-HTTP block apply. Strict
endpoint/mail hooks apply later; they do not establish that unrelated installed
plugins cannot trigger local HTTP/mail during startup. The bootstrap therefore
still requires an explicitly authorized disposable environment.

## Verification and changed files

Test-first RED: seven initial harness tests failed on missing harness before
implementation. Additional RED checks caught unsafe live selection and missing
maximum-budget/metric reporting. Fixture integration initially exposed invalid
fixture summary shape and scanner category; fixtures were corrected to match
existing runtime contracts, without production edits.

Final required commands all passed:

```text
npm run lint:php       217 PHP files
npm run lint:js        scanner.js and chat.js syntax checks
npm test              757 PHP tests; 78 frontend assertions
npm run quality       complete lint/tests passed
git diff --check      passed
```

There are **15 new harness/corpus integrity tests**, zero unexpected PHP warnings
and no changed frontend assertions. Final amended logs are `/tmp/oras-ai-m9-task3-review-round1-final-*.log`.
The three passing runner-test names containing `E_WARNING`/`E_USER_WARNING` are
not emitted warnings. Coverage includes schema/IDs/synthetic data, hard grading,
secret redaction, configuration/usage/failures, repeatability, bound arithmetic,
specialized cases, explicit live selection and pre-bootstrap protection.

Runtime production files changed: **none**. Tooling: `package.json`,
`tools/release-evaluation.php`, `tools/evaluation/{harness,fixtures,live}.php` and
`tools/evaluation/render-support-state.js`.
Tests: `tests/evaluation/ReleaseEvaluationTest.php`, independent
`tests/fixtures/release-evaluation-responses.json`. Docs: existing evaluation
plan, new core JSON, this evidence and compact fixture result.

Owner decisions remain: accept/amend thresholds; arrange authorized local key
and qualified saved pricing; review actual live evidence/semantic scores;
decide whether a separate evaluation-only Medium interface is warranted; then
choose a production configuration. No model is selected here. No package, ZIP,
deployment, backup/rollback qualification, release tag, version bump, production
mutation or M9 closure occurred. Work remains unstaged/uncommitted for review.

## Independent review corrections

The independent review found one Important retained-coverage gap and two Minor
tooling issues. All three were reproduced with failing focused tests before
changes. I1 adds the two actual member-facing support-state cases above while
retaining the original six summaries. M1 matches Task 1's separate input/output
round-up: the fractional-rate summary regression now produces 11,186 rather
than 11,185 microdollars. M2 reports an unsuccessful/rejected **case** once and
keeps provider failures/timeouts per call; a successful auxiliary classifier
followed by an answer timeout is no longer labeled malformed. This is a reporting
correction, not a new failure or quality claim about a real model.

Focused RED/GREEN: `/tmp/oras-ai-m9-task3-review-round1-red.log` and
`/tmp/oras-ai-m9-task3-review-round1-focused.log` (15 tests pass). The new retained
support contracts have no model calls or subjective scores. Proposed thresholds,
live configuration blocker and owner-decision gates remain unchanged.
