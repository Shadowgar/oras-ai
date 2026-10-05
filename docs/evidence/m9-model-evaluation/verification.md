# M9 Task 3B-DEBUG — OpenAI transport diagnosis

Date: 2026-10-05. **OPENAI TRANSPORT: READY FOR LIVE CORPUS**, under the
proven process-scoped disposable OpenAI exception described below.
**Luna/Low model release qualification remains incomplete: no corpus run.**
This section supersedes the unresolved transport diagnosis in Task 3B below;
all prior evidence and the original conservative settlement are retained.

## Baseline and exact failure layer

Branch `m9/production-release`; HEAD
`08cbe4255fd89243c37e6d670c514c9b0e1a6e8e`; plugin/package/runtime version
0.2.1. Starting worktree contained only the existing modified `verification.md`
and untracked `live-preflight.json`. Existing harness/corpus work was preserved.
Starting local accounted spend was **385 microdollars ($0.000385)**, with zero
outstanding reservations.

**WORDPRESS RUNTIME KEY: PRESENT.** The same disposable tests CLI/PHP runtime
resolved the configured constant through `ORAS_AI_Config`; constant presence
matched Config presence, with no empty override. No credential value was printed
or written by this task. PHP **8.1.34**, WordPress **7.2-alpha-63427**;
WordPress HTTP, cURL and OpenSSL were available.

Unauthenticated HEAD from the same CLI container resolved DNS, verified TLS and
received HTTP **421**, with cURL error 0. This proves a responding HTTPS endpoint,
not authentication. The first WordPress GET `/v1/models` was preempted locally:

- WP_Error: YES; `oras_registration_desk_external_http_blocked`.
- Safe message: `External HTTP is disabled in the Registration Desk test runtime.`
- HTTP response/status: none; duration 0.003625 seconds; no network dispatch.

The source is the must-use fixture
`/var/www/html/wp-content/mu-plugins/oras-registration-desk-test-guard.php:99`,
registered on `pre_http_request` at `PHP_INT_MIN`. Its source counterpart is
`/home/rocco/projects/ORAS-Tickets/scripts/fixtures/oras-registration-desk-test-guard.php`.
It blocks non-Intuit external HTTP and remains loaded under `--skip-plugins`.
The original preflight's final gate returned that preempted error unchanged.
WordPress returns early on preemption, before the response debug observer fires;
this explains the earlier zero observed responses and generic failure.
`WP_HTTP_BLOCK_EXTERNAL=false` did not exclude this plugin filter.
The second must-use QBO guard blocks Intuit only and was not the OpenAI blocker.

## Local correction and bounded live results

No must-use plugin, owner configuration or environment file was edited. A
**process-only final filter** changed only this exact guard's WP_Error to `false`
for the approved URL/method (`GET /v1/models`, or `POST /v1/responses` in its
respective diagnostic process). Other endpoints remained blocked. The filter
was removed before exit; all persistent Registration Desk/QBO guards and mail
boundaries remain intact. Future corpus execution must explicitly carry this
same narrow authorized exception into its disposable bootstrap; the unchanged
harness must not be assumed to override a must-use guard automatically.

After correction, the one authenticated non-generation network probe returned
**HTTP 200**, Content-Type `application/json`, WP_Error NO, 0.970125 seconds.
No model-list body was printed or retained. Only after this evidence established
connectivity/authentication was the direct synthetic Responses probe sent.

| Measurement | Direct WP diagnostic A | Accounted ORAS preflight B |
| --- | --- | --- |
| Generation requests | 1 | 1; also the fresh post-correction qualification preflight |
| Endpoint / method | `https://api.openai.com/v1/responses` / POST | Same |
| HTTP / object / status | 200 / response / completed | 200 / response / completed |
| Returned model / requested reasoning | gpt-5.6-luna / Low | gpt-5.6-luna / Low |
| Usage returned / input / output | YES / 10 / 5 | YES / 10 / 5 |
| Output text present | YES; text not retained | YES; text not retained |
| Request ID available | YES; safe ID in compact artifact | YES; safe ID in compact artifact |
| Duration | 2.434484 seconds | 1.795951 seconds |
| Accounting | Outside plugin ledger; may incur provider billing | Normal shared reservation/reconciliation |
| Calculated cost at unchanged qualified rates | $0.000008; excluded from plugin ledger | $0.000008 reconciled |

Both sent a single synthetic `Reply with OK.` user message, Low reasoning, no
tools/search/member data, timeout 20 seconds, zero redirects and output bound
**16**, the [current Responses API minimum](https://developers.openai.com/api/reference/python/resources/responses/methods/create).
The [official Luna model documentation](https://developers.openai.com/api/docs/models/gpt-5.6-luna)
confirms Responses/Low support. Production fields already match:
`model`, `reasoning: {"effort":"low"}`, `max_output_tokens`, and role/content
`input` messages. No legacy `reasoning_effort` is used. Both requests use JSON
Content-Type and internally constructed Bearer authentication, fully omitted
from evidence. `wp_remote_post` supplies POST; WordPress's TLS verification
default is true, matching the explicit direct probe. Endpoint, body decoding,
usage parsing, redirects and timeout were compared without changing behavior.
No provider error type/code/message was returned by the successful requests.

The B request serves both direct-versus-accounted comparison and the required
fresh preflight; no third generation request was made. There were two actual
Responses calls, one actual authenticated model-list GET and one unauthenticated
HEAD in this task. The initial blocked WP probe had no network call. The full
71-case corpus was **not** run; no repeated retries or alternative models.

## Small operational correction, test first

A separate source-confirmed diagnostics gap was corrected: the paid transport
previously replaced WP errors with its generic error and adapter handling discarded
structured provider error metadata. This did not cause the connectivity failure.
The two production files now retain a bounded failure event in the existing
100-entry operator audit log (`provider.openai_transport`): source, HTTP status,
allowlisted provider type/code or local transport code, and a strictly shaped
request ID when available. Unknown strings become `other`; malformed IDs are
omitted. No message, prompt, output/body, Authorization or arbitrary header is
stored. Member-facing WP_Error/data and existing adapter error contracts remain
unchanged. No request/model/prompt/pricing/accounting behavior was modified.

Three permanent regressions in `tests/cost/SiteWideAccountingTest.php` first
failed on missing diagnostics (**RED**, `/tmp/oras-ai-m9-debug-red.log`), then
passed after the minimal correction. They cover structured 429 metadata with
ArrayAccess headers, the exact local blocker, secret/prompt/header exclusion,
malformed/unknown metadata, unchanged member errors and preserved conservative
settlement. Focused GREEN: `/tmp/oras-ai-m9-debug-focused-green.log` (3 tests).
Full GREEN: `/tmp/oras-ai-m9-debug-green.log` (760 PHP tests).
Live execution used exact copies of the changed checkout classes in temporary
container storage, not an installed/deployed plugin.

## Final accounting, verification and handoff

The prior **$0.000385** remains untouched. B reconciled
`ceil(10 * 0.20) + ceil(5 * 1.20) = 2 + 6 = 8` microdollars.
Ending spend: **$0.000393**; unknown-usage portion: **$0.000385**;
outstanding reservations: **$0**; $10 warning: not reached;
remaining $20 ledger headroom: **$19.999607**. Direct A is explicitly outside this
ledger and was not inserted, refunded or double-counted. Its observed usage
would add $0.000008 at configured rates to a provider-cost estimate, not local
accounting. Quotas, warning/stop, prices, runtime model/reasoning and all product
output caps are unchanged. No corpus quality scores or release qualification
are inferred from two trivial successful calls.

Normal required verification passed: `npm run lint:php` (217 files),
`npm run lint:js`, `npm test` (760 PHP tests; 78 frontend assertions),
`npm run quality`, and `git diff --check`, with zero unexpected warnings.
Final logs: `/tmp/oras-ai-m9-debug-final-*.log`.
The [compact safe diagnostic artifact](transport-debug.json) contains only
bounded measurements, request IDs and non-content ledger state.

Next: owner review of the root cause, safe diagnostic patch and scoped disposable
bootstrap exception, then a separately authorized corpus run through the retained
harness and normal accounting. The unmodified full corpus launcher was not run
or qualified in this diagnostic task. No commit, version bump, package,
deployment, production access or M9 closure. Stop for owner review.

---

# M9 Task 3B — Live Luna/Low qualification

Date: 2026-10-05. **LUNA/LOW: NOT QUALIFIED**.

**Stopped after the single unsuccessful preflight; no retry or corpus execution.**
Credential and local pricing prerequisites are now present. No provider response
was observed, so Responses API model/account acceptance, Low acceptance and
provider usage remain unverified. This is an unresolved transport/environment
failure, not evidence of a model-capability, prompt or token-cap failure.
The historical Task 3 fixture report below is preserved separately; its missing
credential/pricing and pending-threshold statements are superseded by this section.

## Current baseline and scope

| Item | Task 3B result |
| --- | --- |
| Branch / HEAD | `m9/production-release` / `08cbe4255fd89243c37e6d670c514c9b0e1a6e8e` |
| Origin | Fresh fetch succeeded; ahead/behind 0/0 |
| Starting worktree | Clean; evaluation harness/corpus already committed at this HEAD |
| Plugin header / runtime constant / package version | 0.2.1 / 0.2.1 / 0.2.1 |
| Fresh baseline quality | Passed: 217 PHP lint files, 757 PHP tests, 78 frontend assertions, zero unexpected warnings |
| Baseline log | `/tmp/oras-ai-m9-task3b-current-baseline-quality.log` |
| Credential | **OPENAI CREDENTIAL: PRESENT**; presence only |
| Environment | Authorized disposable `oras-wp-env` tests site, `http://localhost:8889`; native WP CLI with plugins/themes skipped |
| Transport provenance | Six exact checkout classes copied to temporary container storage; actual `ORAS_AI_Paid_OpenAI_Transport` and durable native ledger; no plugin activation or installation |
| Changes | Local qualified pricing plus one conservatively settled ledger attempt; repository evidence only |

No credential value, header, provider body, identifier, private user content or
exception message is retained. The existing running wp-env stack and unrelated
owner files are preserved. No production WordPress access, model/reasoning
configuration change, version bump, package, deployment, staging or commit.

## Pricing and frozen controls

The [official Luna documentation](https://developers.openai.com/api/docs/models/gpt-5.6-luna)
was checked against the owner's approved prices. The existing
`ORAS_AI_Cost_Config::update()` mechanism persisted the following in the
**disposable environment only**, under `pricing['gpt-5.6-luna']`:

```json
{
  "input_microdollars_per_million_tokens": 200000,
  "output_microdollars_per_million_tokens": 1200000,
  "unit": "per_million_tokens"
}
```

One dollar is 1,000,000 microdollars. Thus 1,000,000 input tokens produce
200,000 microdollars = **$0.20**, and 1,000,000 output tokens produce
1,200,000 microdollars = **$1.20**. Each call rounds input and output costs up
separately, as the existing ledger requires. Cached input officially costs
$0.02/million, but the schema has no separate cached-input rate: all reported
input remains conservatively charged at $0.20/million. No accounting feature
was added. **25/day, 150/month, 5/minute, $10 warning and $20 stop are unchanged**.

## Single preflight and actual accounting

One minimal `Reply with OK.` request was attempted through the native shared
transport using `gpt-5.6-luna`, `reasoning.effort=low`, output cap 128 and timeout
20 seconds. It used the `domain_classifier` accounting source; this is an API
preflight, not a retained classifier scenario. Serialized bytes plus the existing
1,024 framing allowance bounded input at 1,154 tokens. Its ceiling was
`ceil(1154 * 0.20) + ceil(128 * 1.20) = 231 + 154 = 385` microdollars.

The transport attempt was unsuccessful. Its exact returned error code was not
retained by this minimal probe; the production transport uses the bounded generic
`oras_ai_paid_call_unavailable` error for failed calls. No provider-specific code
or HTTP response was observed. The response observer recorded zero events. **This does not establish
zero network dispatches or zero provider billing.** The ledger claimed one
attempt and conservatively settled its full maximum because usage was unknown.
The original observer's initial `not_dispatched` label is not a valid dispatch
conclusion. The [sanitized preflight record](live-preflight.json) explicitly
normalizes that label to `no_provider_response_observed`, with this limitation.
No further provider request, model catalog lookup or retry was made.

| Accounting / reliability item | Actual result |
| --- | --- |
| Transport attempts / observed provider responses | 1 / 0; network dispatch count unknown |
| Corpus cases / successful provider completions | 0 / 0 |
| Actual input / output tokens | Unknown / unknown; ledger zeros mean no known tokens, not zero consumption |
| Starting / ending accounted spend | $0 / **$0.000385** |
| Provider-reconciled cost | Unavailable; entire $0.000385 is conservative unknown-usage settlement |
| Outstanding reservations | $0 after settlement |
| Cost by family | Classifier-source preflight $0.000385; answer, summary and scanner $0 attempted |
| Member question quota consumption | 0 daily / 0 monthly |
| $10 warning / $20 remaining headroom | Not reached / **$19.999615** |
| Transport failures / provider-specific failures / timeouts | 1 / unknown / unknown |
| Provider latency median / p95 / max | Unavailable; no observed response sample, including every family |

No-network diagnostics found external HTTP blocking disabled, WordPress not
blocking the OpenAI endpoint, and cURL/OpenSSL available. They do not identify
the failure cause. The local pricing and durable settlement remain in place;
accounting was not reset or refunded merely because the preflight failed.

## Retained corpus, frozen gates and unmeasured results

Corpus `oras-release-core-v1` remains **71 cases: 68 live eligible and 3 fixture
only**. SHA-256 remains
`9b7c7a4bee28a98cbcfd6f1abadd269c1612c16edd4505741c1ed4ac559b7fd3`.
No prompt, expectation, category, grader or threshold changed.

The owner has now approved and frozen: overall pass rate >=95%, mean >=4/5,
median >=4/5, every category mean >=3.8/5 and pass rate >=90%. Zero final security,
privacy/IDOR, authoritative-current-fact, URL, payment-completion or unconfirmed
side-effect failures are allowed. Earlier fixture rule passes are not live
quality or semantic hard-gate evidence.

An **offline planning calculation only**, using the retained harness's serialized
byte/framing limits and separate round-ups, gives a full live-eligible ceiling of
**121 calls, 10,097,904 input tokens, 98,784 output tokens and $2.138169**.
Including the preflight settlement would total at most $2.138554, within the
site $20 stop. The existing 100-call/$2 batch ceilings would require explicit
nonoverlapping batches. **No live batch was admitted or executed**, because the
API preflight did not succeed. This ceiling is not observed token usage.

| Required qualification measurement | Task 3B live result |
| --- | --- |
| Hard gates | Not evaluated; no live pass/failure rate asserted |
| Overall pass rate / mean / median | Unavailable / unavailable / unavailable |
| Per-category pass rates / means | Unavailable for knowledge, general astronomy, current astronomy, weather, member, support, security and partial failure |
| Six SUP-004 summaries / maximum output | Not run / unknown; 160-token cap unchanged |
| Five retained classifier domains / maximum output | Not run / unknown; 128-token cap unchanged |
| Four synthetic scanners / largest output / headroom | Not run / unknown / unknown; 12,000-token cap unchanged |
| Main answer median / p95 / maximum tokens | Unknown / unknown / unknown; 800-token cap unchanged |
| Cap hits, truncations, damaged answers | Not measured |
| Hostile grounding | All retained pass/price/event/member/horizon/provider/URL/payment scenarios untested live |
| Raw-model compliance / deterministic corrections | Not measured live; historical fixture corrections are separate |

**Recommendation: LUNA/LOW: NOT QUALIFIED.** Classification is an unresolved
transport/environment blocker in the provider-reliability qualification gate.
No model capability or deterministic pipeline defect is established by this
attempt. The owner must decide the bounded investigation/next qualification
attempt after reviewing this failure. No Medium or alternative-model testing,
production recommendation, packaging or M9 closure follows from these results.

## Task 3B normal verification

After these evidence-only changes, all required commands passed:
`npm run lint:php` (217 files), `npm run lint:js`, `npm test` (757 PHP tests and
78 frontend assertions), `npm run quality`, and `git diff --check`.
Unexpected warning count: zero. Logs: `/tmp/oras-ai-m9-task3b-final-*.log`.
Runtime files are unchanged. Evidence is left uncommitted for owner review.

---

# Historical Task 3 fixture evidence (prior to Task 3B)

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
