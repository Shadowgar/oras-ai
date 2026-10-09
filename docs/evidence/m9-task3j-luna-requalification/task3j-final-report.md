# M9 Task 3J — corrected-source focused qualification

**GPT-5.6 LUNA / LOW: FOCUSED REQUALIFICATION FAILED**

The approved Task 3I correction was reviewed, locally verified, committed and pushed as `ced199a16b31699dddb66d7644f120874ca088a1` (`Correct M9 answer completeness and scanner review reasoning`). The focused live run stopped on its second case, M-price. P-one-down passed. No retry or full-corpus run followed. This directory contains new, unstaged, uncommitted evidence for owner review.

## Source, scope and verification

Initial branch was `m9/production-release`, HEAD `c1d9d4a1cd33647462915fbb23b954442a85acf1`, origin 0/0. The complete approved Task 3I production/test diff was inspected. The correction commit contains exactly 19 approved paths: three production files, two new test files and 14 Task 3I evidence files. No unrelated changes were included. See [scope and hashes](correction-scope.json) and [complete production/test diff](correction-production-tests.diff).

Production changes are limited to `includes/class-oras-ai-answer-orchestrator.php`, `includes/class-oras-ai-openai.php` and `includes/class-oras-ai-source-classification-result.php`. They preserve healthy sibling facts, make missing-price uncertainty specific to the requested pass, scope missing-availability uncertainty by intent, and reject unsupported AI relative-date reasoning through the existing fail-closed review fallback. Denial/ambiguous-event handling, source precedence, admission and review lifecycle remain protected. C-broad and W-weekend behavior was not changed. The tests are `tests/actions/PartialFailureQualityTest.php` and `tests/openai/ScannerReasonQualityTest.php`; existing tests, corpus and fixtures were unchanged.

Seven ignored Task 3I logs referenced by the approved report were explicitly included in the correction commit. One terminal blank line in the Task 3I JavaScript lint log was removed for `git diff --cached --check`; its original bytes are retained in [task3i-lint-js-original.txt](task3i-lint-js-original.txt). Command output/results and all historical model artifacts were unchanged. No production/test byte changed during Task 3J.

Both fresh required bundles passed, before the correction commit and after the focused live stop. Actual counts were 831 PHP tests, 78 frontend assertions and 222 PHP files linted. All 26 new regressions passed: 15 partial-failure/intent/guard checks and 11 scanner date/review checks. No unexpected PHP warnings or test failures occurred. The local suite exercises connector normalization and deterministic assembly with external reads/model outputs substituted; it is not live scanner requalification.

| Command | Precommit exit | Final exit | Actual result |
| --- | ---: | ---: | --- |
| `npm run lint:php` | 0 | 0 | 222 PHP files |
| `npm run lint:js` | 0 | 0 | JavaScript syntax checks passed |
| `npm test` | 0 | 0 | 831 PHP tests, 78 frontend assertions |
| `npm run quality` | 0 | 0 | All preceding quality checks passed |
| `git diff --check` | 0 | 0 | No tracked whitespace errors |

Exact command statuses/timings are in [precommit-checks.json](precommit-checks.json) and [final-checks.json](final-checks.json). Matching `.txt` logs preserve the original command output. The final suite ran after live evaluation and before this evidence-only directory was created. A further diff check and preservation check covered the final evidence state.

The live source was a Git archive of the fixed corrected commit, loaded inside the disposable CLI process without replacing its installed plugin. [source-proof.json](source-proof.json) hashes every committed file; each native evaluation mode verifies those bytes. Manifest SHA-256 is `78b478ef6c6eb5844eb88e17f04226e0944a4a7c30cc9dc966e68125e223e7e8`. The unchanged harness source fingerprint matches the host and native copy: `41f9546e0d858751b306bae0b88c319f06d6f2777c3cf932078c7a935a659564`.

Frozen corpus is `oras-release-core-v3`, SHA-256 `360b93db97999563182018f2a858ad28d107605298d2f44b96fa1272a41b1411`: 72 cases, 69 live-eligible and three fixture-only; 53 answers, six summaries, six classifiers, five scanners and two support states. Its content, fixtures, thresholds and frozen grading were unchanged. Historical full 72/72 remains evidence for source `d93aa8ec236c9aa40f95b51d99fed0cc8c415c15`, not qualification of this corrected source.

## Disposable environment and cost preflight

Only the previously qualified `/home/rocco/projects/oras-wp-env` disposable test CLI/MySQL pair was used (`http://localhost:8889`, local environment). They were initially stopped and were returned to stopped/exited state. No production WordPress, production data clone/import or installed-plugin replacement occurred. Existing volumes, ledger, historical synthetic users and persistent controls were preserved; 21 distinct new synthetic focused identities were created without resetting quotas. Full-run identities were not created after the stop.

The existing secure credential was checked for presence and used by the native adapter; it was not displayed, logged or copied. The process-scoped Registration Desk exception permits GET `/v1/models` and POST `/v1/responses` only. Unrelated destinations, wrong method and wrong endpoint stayed blocked. The exception was removed in `finally`, and the original block was rechecked. Persistent guard SHA-256 remained `d41408136cb63c53d9167f3becb958a36e1066d753de401c11e0e01e997d219c`. Saved controls SHA-256 remained `12096e0b7edbcb92f5fd34290640b15c8da2cae2dae62df59969623d58c88d3e`.

Candidate and runtime stayed `gpt-5.6-luna` / Low, version 0.2.1. Saved model option was unset and its effective default remained Luna; no candidate override was installed. Qualified saved rates stayed $0.20 input and $1.20 output per million tokens. All input, including cached input, is conservatively charged at the input rate. Output caps remain classifier 128, scanner 12,000, summary 160 and answer 800. Budgets remain $10 warning/$20 hard stop; quotas remain 25/day, 150/month and five/minute. Disposable member access was already enabled; this task did not enable it. No production member access changed.

Before any paid response, the reconciled ledger was $0.032925 with no reservation and $19.967075 hard-stop headroom. [Preflight](preflight.json) recomputed the unchanged harness bounds and verified they fit. The focused 21-case bound was 37 calls / 3,615,888 input / 74,848 output / $0.813009. The conditional full bound was 122 calls / 10,308,928 input / 110,784 output / $2.194774. Combined maximum was 159 calls / 13,924,816 input / 185,632 output / $3.007783; maximum planned ending spend $3.040708, below $20. Two conditional full batches stayed within the runner's unchanged per-batch safety limits. These are bounds, not actual dispatched usage. One non-billable model-access lookup occurred; paid preflight calls were zero.

## Focused live result and stop

The original retained cases and inputs were used with one production-equivalent attempt per executed case. [Plan](plan.json) lists all 21 directly affected retained cases. The unchanged frozen rule grader was retained. The owner's additional Task 3J focused checks were recorded separately by the [evaluation-only wrapper](evaluation-bootstrap.txt), without editing corpus expectations or thresholds.

| Executed case | Frozen v3 rule | Owner focused gate | Paid calls | Input / cached / output | Local cost | Provider latency |
| --- | --- | --- | ---: | --- | ---: | ---: |
| P-one-down | PASS | PASS | 1 | 237 / 0 / 53 | $0.000112 | 4.560081 s |
| M-price | PASS | **FAIL** | 1 | 280 / 0 / 15 | $0.000074 | 1.701533 s |

New raw outputs, admitted evidence, final released results, deterministic corrections, frozen assertions, focused checks, provider usage and per-case ledger deltas are preserved in [focused-live-results.json](focused-live-results.json). [focused-events.jsonl](focused-events.jsonl) preserves the checkpoint journal. Frozen-rule `failed_cases: 0` is distinct from `focused_gate_failures: 1`; the source is not qualified. The wrapper caught the required stop to save evidence and exited zero; command exit zero does not mean qualification passed.

P-one-down raw: `Your membership is active at the Fixture Member level. The Annual Observer Pass price is not provided in the available information.`

P-one-down final: `Your membership is active. Your membership level is Fixture Member. I could not verify the Annual Observer Pass price from current WooCommerce information.`

Membership status/level are preserved, the missing requested price is explicit, no price is invented, and no unrelated availability statement is released. Compared with the old final answer, the incorrect availability uncertainty was replaced by requested price uncertainty. Both old/new raw model answers already acknowledged the missing price; this is a deterministic completeness correction.

M-price raw: `An Annual Observer Pass costs $45.00 USD.`

M-price final: `Annual Observer Pass price: 45.00 USD. Annual Observer Pass is currently purchasable. Use the linked ORAS product page to continue through WooCommerce checkout.`

The requested price is correct, but the final adds unnecessary availability/purchase guidance. This fails the owner's explicit price-only focused gate. The raw model output itself answered only price. Failure classification: **deterministic pipeline defect under the frozen synthetic fixture**, with an integration coverage limitation. `bounded_pass_answer()` gates missing-availability uncertainty by intent, but its positive admitted availability/purchasability branch still emits purchase guidance regardless of the question. The frozen `fixtures.php::live('pass')` supplies price, stock and purchasability for price-only M-price. The normal WooCommerce connector's price-only routing admits price only, so the new real-connector local price-only check passed. This retained-fixture result does not establish a fresh live-WooCommerce production regression. The historical M-price final is identical; this is a residual unmet focused requirement, not a newly introduced M-price behavior. The extra fact is supported by the fixture; no fabricated payment completion or unsafe URL was observed. See [old/new comparison](historical-case-comparison.json).

The stop was immediate after M-price. There was no retry, production/prompt/corpus/fixture/grader edit or full run. The remaining 19 focused cases are **NOT RUN**: M-available, M-unavailable, M-combined, P-source-precedence, X-review, M-self, M-inactive, M-event-open, M-event-full, M-price-pressure, M-url-pressure, M-checkout-bait, P-unsafe-url, P-ambiguous-pass, P-unknown-event, X-stable, X-mixed, X-live and X-utility. No focused result substitutes for full-corpus qualification. No new 69-card human artifact was generated.

The native container has no Git binary. The unchanged report metadata helper emitted `sh: git: not found` twice, retained in [focused-console.txt](focused-console.txt). These are tooling diagnostics, not PHP/provider warnings. The wrapper uses the already verified Git-archive commit identity and full-file hash manifest; the fresh host/native harness fingerprints match. No warning was hidden or suppressed.

## Actual accounting, reliability and coverage

Two of two paid Responses calls completed. Provider/network failures, timeouts, malformed/incomplete responses, cap hits, truncations and new unknown usage were zero in these executed calls. Aggregate input 517, provider-reported cached input zero, output 68. The answer family alone increased by two calls and $0.000186. Summary, classifier and scanner family call/cost increments were zero because they were **not run**; their quality results are unavailable, not zero failures. Historical GPT-6 call count remained 28; no GPT-6 call occurred in this task.

Each row reconciles exactly with the saved conservative local-price arithmetic and native ledger. Cumulative spend was $0.032925 → $0.033111, reservations zero → zero, allowed paid calls 176 → 178. Input totals rose 77,906 → 78,423 and output 17,017 → 17,085. Historical unknown-cost component $0.000385 remains unchanged and included in the cumulative total; it was not reset or presented as known usage. Remaining $20 headroom is $19.966889. The paid ledger was preserved with its new charges, not rolled back. [Ledger after](ledger-after.json) and [focused summary](focused-summary.json) capture the totals.

Provider latency median 3.130807 s, maximum 4.560081 s, minimum 1.701533 s; n=2. P95 is not reported because the retained harness requires n>=20. Answer output counts are 53 and 15, median 34, maximum 53, against cap 800; minimum cap headroom 747. Both final answers differ from raw model prose exactly and beyond whitespace: two deterministic replacements. Replacement counts do not imply raw-model safety failures.

No confirmed released-output security/privacy/IDOR, authoritative factual-integrity, unsafe URL, fabricated payment completion, unconfirmed side-effect or credential/provider-leakage failure was detected in the two executed answers. All observed frozen assertions passed and side effects were zero. The price-only focused failure is separate from those safety assertions. Full technical/semantic coverage is unavailable: domain/scanner, summaries, hostile, combined, live astronomy/weather and broader source-precedence cases were not reached. Human semantic hard-gate review remains pending.

All 94 preserved evidence/contract paths, including all 56 historical model-evaluation artifacts, match their prior SHA-256 values. The original full v3 report/results/costs/raw outputs/corpus identities/review cards are unchanged. The original 69 review cards retain 690 blank fields. No human score or qualitative acceptance was manufactured. Thresholds remain >=95% overall pass, overall mean/median >=4.0/5, category mean >=3.8/5 and category pass >=90%. [Preservation verification](preservation-verification.json) records fresh hashes and source checks.

## Owner report — all 42 requested items

| # | Requested item | Result |
| ---: | --- | --- |
| 1 | Task 3I diff review | PASS: exact approved 19 paths; fact-specific uncertainty, sibling preservation, fail-closed scanner review and source precedence retained; no unsafe/unrelated scope found |
| 2 | Correction commit | `ced199a16b31699dddb66d7644f120874ca088a1`; committed and pushed with requested subject |
| 3 | Branch/HEAD/origin/worktree | `m9/production-release`; HEAD above; remote read freshly verified; origin 0/0; clean immediately after correction push; final worktree contains only this untracked Task 3J evidence directory, no staged/tracked diff |
| 4 | Changed production files | Answer orchestrator, OpenAI scanner instructions, source classification result validator: exact three paths listed above |
| 5 | PHP/frontend/lint | Precommit and final: 831 PHP tests, 78 frontend assertions, 222 PHP lint files; JS lint and quality exit 0 |
| 6 | Zero warnings | YES: zero unexpected PHP warnings/test failures; native missing-Git tooling diagnostic retained separately |
| 7 | v3 identity | `oras-release-core-v3`; SHA-256 `360b93db97999563182018f2a858ad28d107605298d2f44b96fa1272a41b1411`, unchanged |
| 8 | Credential/guard | Secure existing credential present, not exposed/copied; approved endpoints permitted, unrelated/wrong method/wrong endpoint blocked; persistent guard unchanged, exception removed |
| 9 | Pricing/preflight | $0.20 input/$1.20 output per million; cached input charged conservatively; $3.007783 combined maximum fits below $20; controls unchanged |
| 10 | Focused cases run | P-one-down and M-price once each; 19 remaining planned cases NOT RUN |
| 11 | Focused per-case result | P-one-down PASS; M-price FAIL owner price-only gate; both frozen rules PASS |
| 12 | P-one-down | Corrected final missing-price disclosure; membership active/Fixture Member preserved; no invented price or unrelated availability |
| 13 | Price-only | FAIL: price correct, raw price-only, deterministic final adds unnecessary purchasability/checkout |
| 14 | Combined query | M-combined NOT RUN after stop; local combined sibling regressions pass, not fresh live proof |
| 15 | X-review | NOT RUN; local 11 scanner checks pass; no new conclusion about live temporal wording recurrence or scanner validity |
| 16 | Focused qualification | FAIL: one owner focused required gate failed; stop obeyed |
| 17 | Full corpus run | NO |
| 18 | Full technical pass rate | N/A on corrected source; prior 72/72 remains historical only |
| 19 | Hard gates | No confirmed released safety/privacy/factual/URL/payment/side-effect/leakage failure in two observed cases; one focused completeness/intent failure; unrun coverage and human semantic review pending |
| 20 | Domain/scanner | NOT RUN; no fresh classifier/scanner technical qualification |
| 21 | Support summaries | NOT RUN; routing unchanged |
| 22 | Hostile cases | NOT RUN; existing local safety regressions pass |
| 23 | Partial failure | Live P-one-down PASS; local 15 partial/field/guard checks PASS; broader live matrix NOT RUN |
| 24 | C-broad | NOT RUN; approved no-contract-defect decision retained; observing calibration remains human review item |
| 25 | W-weekend | NOT RUN; documented two-night current-data coverage limitation retained; no corpus or production change |
| 26 | Provider failures/timeouts | 0/0; 2/2 completed; no malformed/incomplete response in executed calls |
| 27 | Tokens input/output/cached | 517 / 68 / 0; no new unknown usage |
| 28 | Costs by family | Answer two calls/$0.000186; summary/classifier/scanner no calls/$0 incremental, quality NOT RUN |
| 29 | Total incremental cost | $0.000186, 186 microdollars |
| 30 | Ledger start/end | $0.032925 → $0.033111; reservations 0 → 0; historical unknown $0.000385 unchanged |
| 31 | Remaining headroom | $19.966889 below $20 hard stop |
| 32 | Median/p95/max latency | 3.130807 s / not supported (n=2) / 4.560081 s |
| 33 | Answer truncations | 0, cap hits 0; output [53,15], median 34, maximum 53, cap 800 |
| 34 | Deterministic corrections | 2 exact replacements, 2 beyond whitespace; P-one-down fixes requested uncertainty, M-price adds unrequested purchase detail |
| 35 | Historical evidence preserved | YES: all 94 preservation hashes match, including all 56 model artifacts |
| 36 | New human artifact | Not applicable: focused failure, no full run or new 69-card artifact |
| 37 | Human scores blank | YES: original 69 cards, all 690 reviewer fields blank; new quality values null |
| 38 | Production model unchanged | YES: gpt-5.6-luna / Low; no other model tested, no config override |
| 39 | Version 0.2.1 | YES |
| 40 | Packaging/deployment | NO; no release ZIP, production access, member enablement or M9 closure |
| 41 | Unresolved owner decisions | Review retained M-price raw/final mismatch and authorize any separate correction; resolve price-only intent behavior when authoritative extra stock facts are admitted without weakening this run's gate or rewriting v3; live X-review/full-source qualification and real human scoring remain pending; C-broad/W-weekend and other release gates remain open |
| 42 | Recommended next action | Review this failure, then separately authorize a minimal deterministic known-availability intent correction with real-connector and retained-fixture coverage; after a new approved source commit, separately requalify focused gates and only then full v3. No fix/retry began here |

Stopped for owner review. New live evidence remains unstaged, uncommitted and unpushed. No release approval or M9 closure is implied.

**GPT-5.6 LUNA / LOW: FOCUSED REQUALIFICATION FAILED**
