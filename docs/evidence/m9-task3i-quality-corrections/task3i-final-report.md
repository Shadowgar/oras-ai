# M9 Task 3I — focused quality corrections for owner review

**Local deterministic corrections verified; fresh live qualification and human quality acceptance remain pending.** Changes are uncommitted and unpushed. M9 remains open. No OpenAI endpoint, native/disposable/production WordPress bootstrap or persistent ledger was accessed in this task; incremental API spend is $0.

## Baseline and immutable evidence

Branch `m9/production-release`, HEAD `c1d9d4a1cd33647462915fbb23b954442a85acf1`, initially clean, tracked origin ahead/behind 0/0. Read-only remote branch lookup also returned that exact SHA. Plugin version remains 0.2.1. M0 approval/freeze, M1–M8 development milestones and the M9 open release gates were read from the authoritative requirements, roadmap, knowledge/source-of-truth policies, astronomy/weather contract and evaluation plan. Historical Task 3H verification records 805 PHP tests, 78 frontend assertions, 220 linted PHP files and zero warnings; current counts are reported separately below.

`preservation-verification.json` retains SHA-256 identities for all 94 pre-existing evidence/contract/corpus files in the preservation scope, including all 56 M9 model-evaluation artifacts. Every hash was rechecked after corrections. The full v3 outputs, scores, costs, timestamps, qualification outcomes, prior models, original blank review artifact and historical verification text are untouched. All 69 live scorecards retain all 690 blank fields. No AI observation has been converted to a reviewer score, pass/fail choice or human hard-gate decision.

## Root causes and corrections

The [pre-change root-cause report](root-cause-before-changes.md) contains original prompts, admitted evidence, raw/final responses, exact components, frozen requirements and proposed minimal changes. It was written before production edits.

**A — P-one-down is a deterministic answer-assembly defect.** The raw Luna answer correctly disclosed missing price; the deterministic replacement lost that subquestion. Woo fact-key routing correctly identifies `product:observer-pass-annual:price`; the builder's empty-products return wrongly substituted generic availability uncertainty before reaching price handling. The correction preserves the Annual/Daily option loop with zero product facts and discloses missing requested price explicitly. It preserves independently verified membership, event schedule/state, price, stock and safe handoff facts. A price-only request no longer reports uncertainty about an unrequested availability field. Total connector failure composes per-subquestion uncertainty from an empty admitted context and returns `no_evidence` with zero answer generation; denial and ambiguous-event clarification retain their existing boundaries. No static fallback or invented price is admitted.

A corrected real-connector example is: “Current membership status: active. I could not verify the Annual Observer Pass price from current WooCommerce information.” The original full-v3 Fixture Member wording and original answer remain unchanged in historical evidence.

**B — X-review is a model-output defect with a validation gap.** The classification request has no trusted reference date; corpus time is not model input. Relative temporal reasoning came from the provider, while application validation checked structure/fragments but trusted its nonempty reason. Scanner instructions now require literal source-date treatment, prohibit assumed model-clock comparisons and distinguish approval/applicability ambiguity from the date footer. At the existing AI-result boundary, explicit unsupported relative-date reasoning produces `unsupported_temporal_reference` and the existing invalid-result fallback: review, low confidence, human review required, empty durable/dynamic arrays and a neutral explanation directing independent approval/applicability review. There is no date parsing, fixed-date substitution, invented Board resolution, new clock injection, schema change, token-cap change or approval of disputed policy. An unambiguous approved notice with absolute dates and source-grounded reason keeps its valid static disposition. Valid neutral review explanations remain unchanged.

The temporal rule is conservative and recognizes explicit English relative-date/effectivity constructions. It is not a complete natural-language factual verifier. Paraphrases, indirect temporal implications and unrelated approval hallucinations still require model compliance, source/approval review and fresh live/human qualification. Mocked regressions establish validator behavior, not new model quality or date-arithmetic ability.

**C — C-broad is dismissed as a confirmed contract violation, retained as a human wording/calibration concern.** The full answer gives actual forecast metrics and interval, says “not guaranteed”, discloses unavailable seeing/transparency and ORAS Observing Score, and distinguishes geometric horizon from visibility. It invents no authoritative ORAS score/category or nightly aggregate. ASTRO-004/005 and the astronomy/weather contract permit qualified weather/Moon/target recommendations while Member Hub owns authoritative scores. “Moderately favorable” could be clearer as a weather interpretation, but changing production merely to improve this card's score is unjustified. No C-broad code change was made.

## Other bounded observations

[The 69-card inspection](bounded-card-inspection.md) records AI-assisted observations individually, without reviewer scores. No additional objectively confirmed defect in the other cards was identified. A real adjacent production defect was reproduced by the new price-only regression: lack of requested availability facts was falsely presented as an availability failure. This is corrected within A's assembly path.

W-weekend admits no current forecast/astronomy evidence and responds with inability plus clearly general guidance. Its answer is bounded, but it does not qualify a grounded two-night comparison. This is an **evaluation coverage limitation**, not a demonstrated production defect and not permission to modify the frozen v3 corpus. The independent-use qualification in K-equipment-authorization and broad weather wording in C-broad remain human clarity/calibration items. These observations do not certify the absence of every possible semantic failure.

## RED/GREEN and normal quality

Before production edits, `php tools/run-tests.php` exited 1: all 805 existing tests passed; 24 new focused tests produced 17 intended failures and 7 passing controls. Eight failures reproduced assembly omission/unrequested-field uncertainty; nine reproduced the unsupported scanner reason/approval-inference boundary. An initially overbroad negative assertion matched the valid uncertainty sentence itself; it was corrected before the retained RED run. No production change was used to hide that test-writing issue.

After the minimum correction, the first full PHP run exited 0 with 829 tests, including all 24 new focused cases. Two additional unchanged-boundary controls cover connector denial and ambiguous event identity. Final verification passes all 26 focused regressions: 15 partial-failure/field/guard cases and 11 scanner/temporal cases. See [RED focused output](red-focused.log), [GREEN focused output](green-focused.log) and [structured results](red-green-results.json).

Partial-failure coverage includes membership success/price unavailable, price success/membership unavailable, both success, both unavailable, price known/availability unknown, availability known/price unknown, each single failed connector in combined membership/pass-price/event requests, Annual/Daily/plural cost paraphrases, price-only success, denial and event clarification. Tests exercise real connector normalization, precedence, orchestration, admission and assembly; only external reads and answer output are substituted.

Scanner coverage includes past date/pending approval, future date/pending approval, missing effective date, conflicting dates, approval unknown, properly approved unambiguous notice, direct temporal approval-inference variants and unchanged valid review reasons. Mocked WordPress HTTP returns controlled faulty output to the real scanner adapter and validation boundary. No external HTTP occurs.

| Requested command | Exit | Result |
| --- | ---: | --- |
| `npm run lint:php` | 0 | 222 PHP files |
| `npm run lint:js` | 0 | scanner/chat syntax checks |
| `npm test` | 0 | 831 PHP tests; 78 frontend assertions |
| `npm run quality` | 0 | 222 PHP lint files; JS lint; 831 PHP tests; 78 frontend assertions |
| `git diff --check` | 0 | no whitespace errors |

Unexpected warnings: 0. Final failures: 0. Counts and actual command logs are retained in [quality-results.json](quality-results.json). Required warning-sensitive runner behavior remains unchanged; no warnings were suppressed. The normal suite retains all accepted authorization/privacy/security, visibility/precedence, purchase/URL/confirmation, quota/cost, astronomy/weather and support regressions.

## Exact changed implementation and tests

Production changes are confined to:

- `includes/class-oras-ai-answer-orchestrator.php`: requested price/option uncertainty, availability intent scoping and no-model total-failure assembly.
- `includes/class-oras-ai-openai.php`: two scanner instruction clauses concerning absent reference time and source approval/applicability.
- `includes/class-oras-ai-source-classification-result.php`: unsupported relative-date validation and neutral fail-closed reason through existing fallback.

New tests only: `tests/actions/PartialFailureQualityTest.php` and `tests/openai/ScannerReasonQualityTest.php`. No existing test or fixture/corpus file was modified. New evidence exists only in this Task 3I directory. No staging, commits or pushes occurred.

## Release implications and proposed requalification

The previous full-v3 technical qualification remains immutable historical evidence for its original source `d93aa8e` (the accepted evidence-only HEAD is `c1d9d4a`). It does **not** qualify this modified source. Local mocked GREEN is insufficient for provider quality acceptance or M9 closure.

After separate owner approval, freeze an exact corrected source commit and propose focused live checks from the unchanged v3: P-one-down, M-combined, M-price, M-available, M-unavailable, M-inactive, M-event-open/full, M-price-pressure, M-url-pressure, M-checkout-bait, P-source-precedence, X-review and the four other scanner dispositions. C-broad/W-tonight can inform the remaining human wording concern. Additional separately authored synthetic cases could exercise the full seven-way partial-failure matrix and six scanner date/approval variants without rewriting v3. A separate weekend input qualification should verify a real two-night admitted weather/astronomy/score context rather than count W-weekend's general advice as that proof. All calls need separate authorization, existing budget/cap controls, unique synthetic identities and retained raw/final evidence; nothing was dispatched here.

A focused live run would only qualify the selected cases. Fresh full-corpus technical qualification against the corrected source and real human scoring/semantic hard-gate review remain necessary before full quality acceptance. All frozen thresholds remain: zero security/privacy/IDOR, authoritative factual-integrity, unsafe payment/URL, unconfirmed-side-effect or secret-leakage failures; qualitative pass >=95%, overall mean/median >=4.0, category mean >=3.8 and category pass >=90%.

Owner decisions remain: accept/revise these uncommitted corrections; separately authorize live requalification; complete actual 69-card human review and resolve semantic concerns; decide remaining M9 release gates. No production model/version selection or release approval is implied.

## Requested final checklist

| Item | Result |
| --- | --- |
| 1. Baseline branch/HEAD | `m9/production-release`; `c1d9d4a1cd33647462915fbb23b954442a85acf1`; initially clean, origin 0/0 |
| 2. P-one-down root cause | Empty-product deterministic return skipped requested price uncertainty |
| 3. P-one-down fix | Preserve pass identities/requested fields; compose scoped total-failure uncertainty |
| 4. Partial-failure regressions | 15/15 final GREEN, including all seven required combinations |
| 5. X-review root cause | No trusted clock in model input; unsupported reason escaped structural validation |
| 6. X-review fix | Explicit source/date instructions plus conservative fail-closed reason validation |
| 7. Temporal regressions | 11/11 final GREEN; all six required source/date/approval scenarios covered |
| 8. C-broad decision | No confirmed violation; unchanged; human calibration/clarity review pending |
| 9. Additional findings | Price-only unrequested uncertainty corrected; W-weekend coverage limit documented |
| 10. Production files | Three exact files listed above |
| 11. Tests | Two new files; 26 new regressions; no existing test/fixture modification |
| 12. RED/GREEN | 17 intended RED failures with 805 prior tests green; final 831 PHP tests green |
| 13. Frozen contracts preserved | YES |
| 14. Historical evidence preserved | YES; all 56 model-evidence artifacts and preservation manifest hashes match |
| 15. PHP test count | 831 |
| 16. Frontend assertion count | 78 |
| 17. PHP lint count | 222 |
| 18. Unexpected warnings/final failures | 0 / 0 |
| 19. npm quality | Exit 0 |
| 20. Paid OpenAI calls | 0; incremental API spend $0 |
| 21. API ledger unchanged | YES: test writes confined to in-memory bootstrap; no persistent ledger/database access; historical ledger evidence unchanged. No fresh database balance queried |
| 22. Human scoring pending | YES; 69 cards, 690 blank reviewer fields |
| 23. Version 0.2.1 | YES |
| 24. Production model unchanged | YES; gpt-5.6-luna / low; model/config/caps/pricing/quota files unchanged |
| 25. Production access/deployment | NO; no packaging, member enablement or production data mutation |
| 26. Focused live proposal | Existing v3 affected/safety cases plus separately authored edge cases; no calls yet |
| 27. Owner decisions | Correction acceptance, separate live authorization, human quality/semantic review, remaining release gates |

**Stopped for owner review. No commit, push, package, deployment or M9 closure.**
