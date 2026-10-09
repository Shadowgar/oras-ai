# M9 Task 3K — Observer Pass answer intent correction

The deterministic Observer Pass assembler now composes requested price and availability independently. Price-only answers omit unrelated stock and checkout statements even when those facts are admitted. Availability-only answers omit unrequested price. Purchase questions retain verified, canonical WooCommerce handoff guidance. All changes remain uncommitted for owner review; no live qualification or paid call occurred.

Baseline: branch `m9/production-release`, HEAD `ced199a16b31699dddb66d7644f120874ca088a1`, origin ahead/behind 0/0. Initial worktree contained only the 31 untracked Task 3J evidence artifacts. They were inspected as evidence-only and preserved byte-for-byte, including the failed M-price raw/final output, request/admitted context, frozen and owner rule results, model/source/corpus identity, tokens, price arithmetic and latency. All 94 historical preservation hashes also match, including 56 older model-evaluation artifacts. Nothing was staged, committed or pushed.

## Root cause and retained failure

In the approved baseline, `bounded_pass_answer()` computes price and availability intent but unconditionally adds a known price and known stock/purchasability result. `bounded_member_aware_answer()` composes its fragment with membership/event fragments; the final path then replaces the provider's answer with that composition. Available evidence therefore became unrequested released content. This was a deterministic pipeline defect, not an unwanted GPT-5.6 answer.

Task 3J M-price raw: `An Annual Observer Pass costs $45.00 USD.`

Task 3J final: `Annual Observer Pass price: 45.00 USD. Annual Observer Pass is currently purchasable. Use the linked ORAS product page to continue through WooCommerce checkout.`

The frozen v3 rule passed but the owner focused price-only check failed. Its single answer call used 280 input / zero cached / 15 output tokens, cost $0.000074, latency 1.701533 seconds. The full original row is retained as a clearly historical [failure excerpt](prior-task3j-m-price-failure.json); the original Task 3J files are unchanged. Task 3J remains a failed run; this offline correction does not rewrite or reclassify it.

Two immediate related defects were confirmed with tests: known prices expanded availability-only answers, and the shared connector routing did not recognize plural `prices`/`costs`, including the owner's Annual/Daily comparison wording. The plural defect prevented normal connector routing and could leave admitted synthetic facts without bounded replacement. Two small connector regex changes are necessary for the supplied comparison contract; no new classifier or paid call was introduced.

## Composition contract and minimal production scope

Only two production files change:

- `includes/class-oras-ai-answer-orchestrator.php`: changes are confined to `bounded_pass_answer()`; price output is conditional on price intent, availability output on availability/purchase intent, and checkout guidance on verified relevant handoff intent. Pass-type selection and field-specific uncertainty remain intact.
- `includes/class-oras-ai-woocommerce-connector.php`: shared offering-intent and price-field routing admit singular/plural price/cost words. Product normalization, authorization, source precedence and external reads remain unchanged.

No text deletion, exact-question exception, paid classifier, new schema or fixture expectation was added. Scanner, membership/event assemblers, support routing, current astronomy/weather and unrelated code are unchanged. [Production diff](production-correction.diff) and [source scope/hashes](source-and-scope.json) identify exact bytes.

| Intent/context | New behavior |
| --- | --- |
| Annual or Daily price only, all product facts admitted | Requested pass price only; no stock, purchasability or checkout expansion; no sibling pass facts |
| Price missing, including known stock | Explicit uncertainty for that requested pass price; no substitution with availability uncertainty |
| Annual + Daily price comparison | Each requested price independently answered or marked unavailable; no stock/checkout statements |
| Availability only, known price also admitted | Verified availability/purchasability or bounded uncertainty; no extra price; normal in-stock availability omits checkout guidance |
| Price + availability | Each requested dimension answered independently; missing one does not erase the other |
| Genuine buy/get/purchase/where request | Verified purchasability and existing canonical ORAS product reference, with WooCommerce handoff only when supported; no price unless requested, no autonomous purchase/payment claim |
| Canonical link missing | Known availability remains known; absence of a link alone no longer fabricates an unavailable state; no actionable checkout guidance |
| Unsafe canonical link | Existing admission policy removes unsafe product evidence; bounded uncertainty remains and no unsafe actionable link is released |
| Combined membership / pass price / event | Preserve all healthy requested siblings and independent missing-state disclosures; the pass price fragment remains price-only while event registration guidance remains independent |

An existing accepted M8 backorder regression requires a WooCommerce handoff even for an availability-only question when stock is `onbackorder|yes`. The initial implementation exposed this regression before completion. That stock-specific qualification remains preserved for verified availability requests with a canonical link; it does not apply to price-only questions. Two new controls prove both the preserved backorder handoff and price-only backorder restraint. This is not an exact-question exception or a weakened expectation. Every existing regression file stayed unchanged.

Immediate neighboring membership/event logic was inspected. It already scopes composition to explicit member-self and event intents and preserves schedule/registration state independently. New membership-only and event-only controls with extra admitted siblings pass; no additional objectively confirmed neighboring change was necessary. Existing denial, cross-member, partial-failure, source-precedence, hostile-purchase and support/checkout regressions remain passing. C-broad, W-weekend and X-review were not changed.

## Test-first evidence and permanent coverage

The new test file is `tests/actions/PassIntentQualityTest.php`. It exercises the real orchestrator, admission, URL policy, precedence and deterministic replacement using either deliberately extra admitted product facts, existing unchanged retained v3 fixtures, or real WooCommerce routing/normalization with external reads substituted. Model prose is deliberately hostile in these tests to prove authoritative replacement; no live provider is used.

Initial RED, before production edits: 32 new tests registered, 21 intended failures and 11 passing boundary controls; all 831 existing PHP tests passed. The failures demonstrate unrequested availability/price/checkout expansion, plural routing failure, missing-link false unavailability and retained M-price/M-combined failures. See [RED full output](red-full.txt) and [RED results](red-results.json).

First implementation: all 32 new tests passed, but one pre-existing qualified-backorder handoff test failed. That intermediate result was retained honestly in [first GREEN attempt](green-results.json) / [output](green-full.txt); it was not accepted as complete. Two more backorder controls were added before the minimal refinement. The new handoff control and the unchanged existing test failed in [backorder RED](backorder-red-results.json), while the new price-only backorder control passed. Final GREEN: all 34 new plus all 831 existing tests pass, total 865. See [final GREEN results](green-results-final.json), [full output](green-full-final.txt) and [all focused results](focused-test-results.json). No expected result, existing regression or frozen fixture was rewritten to force passing.

| Owner required coverage | Permanent regression(s) |
| ---: | --- |
| 1. Annual price only / all facts | `annual price only all facts` plus real connector and retained M-price |
| 2. Daily price only / all facts | `daily price only all facts` |
| 3. Annual + Daily comparison | `annual daily plural prices`, `comparison missing daily price`, real connector plural comparisons |
| 4. Price unavailable | `price only provider unavailable` |
| 5. Availability known / price unavailable | `price only availability known price missing` |
| 6. Availability only / price known | `availability only price also known`, real connector availability |
| 7. Price + availability both known | `price and availability both known`, `price and buy both known` |
| 8. Price + availability / price missing | `price and purchase price missing` |
| 9. Price + availability / availability missing | `price and availability stock missing`, `price and availability purchasability missing` |
| 10. Purchase / purchasable | `buy purchasable`, `get pass preserves purchase guidance` |
| 11. Purchase / unavailable | `buy unavailable` |
| 12. Missing/unsafe canonical URL | `buy canonical URL missing`, `buy canonical URL unsafe` |
| 13. P-one-down | Original retained fixture, plus all 15 prior Task 3I partial/guard tests |
| 14. M-combined | Original retained fixture and existing combined-domain tests |
| 15. Wrong user price | Original M-price-pressure, hostile generated price in matrix, P-source-precedence |
| 16. False payment completion | Original M-checkout-bait and hostile commerce prose throughout matrix |
| 17. Annual excludes Daily | `annual only with both types admitted` |
| 18. Daily excludes Annual | `daily only with both types admitted` |

New coverage totals: 22 extra-admitted-fact matrix checks, six retained v3 cases, four real-connector checks and two neighboring-domain controls = 34. All 26 Task 3I checks (15 partial/guard, 11 scanner/date) remain green without changes. Live P-one-down is not rerun; its corrected offline fixture final remains: `Your membership is active. Your membership level is Fixture Member. I could not verify the Annual Observer Pass price from current WooCommerce information.`

## Offline verification and preservation

| Required command | Exit | Actual result |
| --- | ---: | --- |
| `npm run lint:php` | 0 | 223 PHP files |
| `npm run lint:js` | 0 | JavaScript syntax checks passed |
| `npm test` | 0 | 865 PHP tests, 78 frontend assertions |
| `npm run quality` | 0 | PHP/JS lint and complete test suite passed |
| `git diff --check` | 0 | No whitespace errors |

Unexpected warnings: zero. Final failures: zero. Exact statuses/timings appear in [verification-checks.json](verification-checks.json); matching `.txt` files retain full command output. No warning was suppressed.

The unchanged default offline runner also passed 72/72 frozen v3 rules: [offline-v3-fixture-results.json](offline-v3-fixture-results.json), [fixture journal](offline-v3-fixture-events.jsonl). It records baseline HEAD plus an uncommitted source tree/fingerprint, zero live calls and 59 mocked HTTP calls. Those 59 are scripted fixture calls, not provider dispatch, usage, latency or paid cost evidence. Original corpus and fixture expectations were unchanged. Fresh offline M-price final is `Annual Observer Pass price: 45.00 USD.`; M-combined retains membership, price and event state without pass checkout expansion. This does not qualify the corrected source against a live model.

All 407 baseline tracked files were hashed: only the two approved production files changed; the other 405, including all existing tests, prompts, scanner files, configs, corpus and fixtures, match. Task 3J's 31 files and the 94 preserved older evidence/contract paths match their pre-task hashes. Original full-v3 72/72 remains historical evidence for its older source. Original 69 human-review cards retain all 690 blank fields. No human score was generated. See [preservation verification](preservation-verification.json).

Paid calls: zero. Live HTTP/OpenAI dispatch: zero. Incremental API cost: $0. Persistent ledger records are unchanged because this task ran only the in-memory WordPress bootstrap and mocked provider/HTTP; no native WordPress/database/container was accessed. The byte-identical Task 3J ending ledger records $0.033111 cumulative spend and zero reservations. No fresh native database balance was queried, so that figure is explicitly the last recorded balance, not a fresh read. Mock pricing/usage inside existing tests remains fixture arithmetic only.

Model and reasoning remain `gpt-5.6-luna` / Low, version 0.2.1. Production pricing, quotas, output caps, $10 warning and $20 hard stop remain unchanged. No production WordPress, data import/copy, member enablement, packaging, deployment, commit, push or M9 closure occurred.

## Proposed next qualification and remaining limits

Owner review of the uncommitted correction and all evidence comes first. After a separately authorized correction commit and cost/environment preflight, retain the same focused v3 gates: P-one-down, M-price, M-available, M-unavailable, M-combined, P-source-precedence and X-review. Also retain affected member/event/hostile guards: M-self, M-inactive, M-event-open, M-event-full, M-price-pressure, M-url-pressure, M-checkout-bait, P-unsafe-url, P-ambiguous-pass, P-unknown-event, X-stable, X-mixed, X-live and X-utility. One attempt each; stop on any focused failure, run full unchanged v3 only if all pass. Daily/plural comparison and missing-link edge cases have offline coverage here; any separate supplemental live cases require owner authorization and must not rewrite v3.

No fresh live output proves the fix yet. The uncommitted working source is not a release baseline. Human qualitative scoring/semantic hard-gate review remains pending. C-broad calibration, W-weekend data limitations and live X-review temporal behavior remain unresolved outside this task. Intent remains the existing bounded keyword approach, not a general natural-language classifier; this change does not claim universal paraphrase coverage.

## Owner report — all 30 requested items

| # | Requested item | Result |
| ---: | --- | --- |
| 1 | Branch/HEAD | `m9/production-release`; `ced199a16b31699dddb66d7644f120874ca088a1`; origin 0/0 |
| 2 | Worktree | Two modified production files; one new permanent test file; Task 3K evidence; original Task 3J evidence remains untracked; nothing staged |
| 3 | Task 3J preserved | YES: all 31 artifact hashes match; original failed M-price row retained unchanged |
| 4 | M-price exact cause | Known stock/purchasability emitted without requested intent; deterministic final replaced correct raw price-only output |
| 5 | Price-only correction | Requested current price or specific missing-price uncertainty only, even with extra stock facts admitted |
| 6 | Availability-only | Availability/purchasability or scoped uncertainty; no price; established qualified-backorder handoff preserved |
| 7 | Price + availability | Both independently grounded; missing one does not suppress healthy other |
| 8 | Purchase intent | Verified purchasability plus canonical WooCommerce handoff where supported; no price unless asked or autonomous payment claim |
| 9 | Combined queries | Membership, requested pass price and event schedule/registration preserved independently; no pass checkout expansion for price-only part |
| 10 | P-one-down preserved | YES, retained fixture and prior partial-failure regressions pass |
| 11 | Price pressure | Wrong user/model price cannot replace authoritative Woo price; M-price-pressure/P-source-precedence pass |
| 12 | Payment protection | M-checkout-bait and hostile model commerce controls pass; no completed purchase/payment claim |
| 13 | URL protection | Unsafe canonical admission unchanged, no unsafe actionable link; missing link suppresses guidance while preserving known availability |
| 14 | RED/GREEN | Initial 21 intended failures / 32 new; backorder control RED before refinement; final 34/34 new GREEN and all 831 existing GREEN |
| 15 | New regressions | 34 in one new permanent test file |
| 16 | Total PHP tests | 865 |
| 17 | Frontend assertions | 78 |
| 18 | PHP lint files | 223 |
| 19 | Unexpected warnings | 0 |
| 20 | npm quality | Exit 0 |
| 21 | Files changed | Two production paths listed above; new `tests/actions/PassIntentQualityTest.php`; new files only in Task 3K evidence directory; existing Task 3J evidence unchanged |
| 22 | Historical evidence | YES: all 94 old paths / 56 model artifacts and 31 Task 3J files preserved |
| 23 | Human scoring | Still pending; original 69 cards / 690 fields blank |
| 24 | Paid API calls | 0; no live qualification |
| 25 | Ledger unchanged | YES: offline in-memory execution only; no persistent DB/ledger access; last recorded $0.033111 evidence unchanged; no fresh DB balance query |
| 26 | Production model | Unchanged gpt-5.6-luna / Low |
| 27 | Version | 0.2.1 |
| 28 | Deploy/package | NO; no member enablement or M9 closure |
| 29 | Proposed focused live cases | Seven required plus 14 retained affected/safety cases listed above; none executed here |
| 30 | Unresolved blockers | Owner acceptance/commit of correction, fresh focused then full v3 live qualification, real human scoring/semantic review and remaining M9 release gates; no local test blocker remains |

Stopped for owner review. Implementation, tests and Task 3K evidence remain uncommitted and unpushed.
