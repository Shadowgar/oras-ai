# M9 Task 1 — Site-wide OpenAI accounting

Implementation qualification, 2026-10-02. This closes the Task 1 accounting scope for owner review; it does not close M9 or authorize release, packaging, deployment, model evaluation, or production access.

## Baseline and authority

The coordinating agent freshly verified `m9/production-release`, HEAD and committed M8 closure `56b8ba9a9ef19bc856b4f4be92ed6a61196e206f`, origin ahead/behind `0/0`, clean worktree, and plugin `0.2.1` before any edits. Baseline: 204 PHP files linted, 663 PHP tests, 78 frontend assertions, JavaScript validation and `npm run quality` passed. The existing weather-service warning at line 197 was disclosed. Current runs also emit the pre-existing weather parsing warning at line 207; neither weather implementation nor tests were changed here.

Read authority: frozen `COST-001` through `COST-007`, ADR-0016, M3 closure verification and operations cost-control documentation, M9 audit findings, and the complete owner's Task 1 specification. Frozen values remain 25 questions/day/member, 150/month/member, 5/minute, $10 monthly warning, $20 hard stop, default 4,000-byte member input, 800 answer output tokens and 30-second answer timeout. Settings remain configurable through the existing audited controls. No configured models or pricing values were changed or added.

## Complete paid-call inventory before implementation

The plan at `docs/superpowers/plans/2026-10-02-site-wide-accounting.md` recorded the inventory and chosen caps before production edits. A production search found four dispatches, all to the Responses endpoint, using the configured OpenAI model. No embeddings, other OpenAI endpoints, retries, separate extraction/normalization calls, or scanner workers were found.

| Family | Initiator | Prior input / output / timeout | Prior admission and accounting | Gap |
|---|---|---|---|---|
| `answer` | Authorized member gateway → answer orchestrator; Test Console reuses conversation transport/gateway | 4,000-byte question, 16,000-byte encoded context; 800 tokens / 30s defaults | Member quotas + answer reservation before domain guard; valid answer usage reconciled; local no-call released; ambiguous failures treated estimates as actual tokens | Direct adapter could bypass the ledger; malformed output could lose known usage; unknown estimates looked measured |
| `support_summary` | Validated escalation proposal → summary service | 1,000-byte question plus fixed prompt/schema; 160 tokens / 10s | Existing answer admission and local pricing; reserved answer output; valid normalized usage reconciled | Incorrect extra member question/burst charge; output validation could discard known usage; direct adapter bypass |
| `domain_classifier` | Ambiguous domain-guard request | Member path bounded, direct adapter unbounded; no output limit / 20s | No independent pricing, reservation or reconciliation; incidental outer answer admission | Classifier spend disappeared when answer reservation released; direct adapter had no dollar stop |
| `scanner_classification` | Manual source processing → deterministic rules → source classifier | 30,000 characters of source; metadata unbounded; no output limit / 60s | No shared price/budget admission or accounting | Scanner and mixed extraction spend bypassed the site-wide stop |

Scanner mixed extraction is part of the same request and schema: classification fields, `stable_fragments` with title/content, excluded dynamic claims/types, and validation flags. It is not a second paid family. Existing scanner controls are manual initiation, deterministic rules, changed-source skipping, per-source exclusion and required API configuration. The browser queue stops on processing error. There is no separate scanner-wide kill switch. The member kill switch remains unchanged; the dollar stop applies to scanner regardless.

## Shared architecture and exact limits

`ORAS_AI_Paid_OpenAI_Transport` is the sole production paid HTTP seam. All four adapters pass it a bounded Responses payload. It validates the raw selected model against the existing allowlist, requires qualified local input/output pricing, validates output/timeout/serialized input, reserves or claims the existing reservation durably, then dispatches once with redirects disabled. No paid HTTP retry exists. Raw invalid model configuration cannot silently fall back to a paid default; unrelated config-display normalization is preserved.

| Family | Input qualification | Explicit maximum output | Timeout |
|---|---|---|---|
| Answer | Existing configured member bound; 16,000-byte encoded provider context; complete payload ≤18,000 bytes | Existing configured limit, default 800 | Existing configured limit, default 30s |
| Support summary | Question ≤1,000 bytes; complete fixed-schema payload ≤10,000 bytes | 160; service also respects a lower configured answer cap | 10s; service also respects a lower configured timeout |
| Domain classifier | Question ≤configured member bound (default 4,000 bytes); complete payload ≤140,000 bytes, covering maximum configurable 20,000-byte input with JSON escaping | 128 | 20s |
| Scanner classification including extraction | Existing first 30,000 characters; title ≤1,000 bytes; URL ≤2,048 bytes; type ≤64 bytes; serialized payload ≤210,000 bytes | 12,000 | 60s |

Domain output is exactly `{"domain":"oras|astronomy|crossover|off_topic"}`. The longest minimal concrete JSON is 22 bytes. A 128-token cap leaves substantial structured-response margin without adopting the answer's 800-token allowance. All four enums are qualified with fake HTTP; extra fields, oversized text and incomplete responses fail safely. The adapter rejects text over 512 bytes, including whitespace padding.

Scanner has a materially larger extraction schema. The 12,000-token output allowance covers multi-fragment extraction from the existing bounded source envelope, plus title/reason/classification metadata and excluded-claim lists. The regression returns 12 substantial fragments (roughly 26 KB of durable text), 12 excluded claims, types and validation, with a synthetic reported 7,500 output tokens, and proves the complete result survives. Scanner rejects output text over 96,000 bytes or incomplete provider status rather than truncating a returned extraction or replacing approved artifacts.

These are deterministic contract fixtures, not live-model quality/tokenizer proofs. In particular, low-effort reasoning model completion under 128/12,000 tokens has not been evaluated live. The existing 30,000-character input slice is preserved; added metadata and payload bounds fail closed instead of silently trimming additional fields.

The conservative input reservation is the **complete serialized payload byte length plus 1,024 tokens of framing allowance**, including prompt, schema, role and JSON overhead. One token per serialized byte is a deliberate upper estimate, not measured actual usage. Answer/service reservations are atomically enlarged if needed before dispatch, instead of reserving a second paid call.

## Quotas, accounting and failures

The existing `ORAS_AI_Usage_Ledger` remains the only cost ledger. Member admission uses its existing daily/monthly/burst policy. Each record gains a bounded `source` and `member_question` flag. Auxiliary domain, support and scanner work is cost-only; direct adapter calls also cannot create a member question implicitly.

An ambiguous allowed question produces two distinct paid records (classifier + answer), 320 synthetic microdollars at the fixture rates, one member daily/monthly question and one burst attempt. A paid off-topic classification retains its cost while the unexecuted answer reservation releases; successful-question count remains zero. Support summary generation adds its own paid record and no extra question or burst attempt. Existing proposal generation makes one summary call; confirmation/status/replay/cancellation reuse stored summary and make no additional model call.

Reservation lifecycle:

- `open`: durable admitted reservation; releasable on a definite no-dispatch failure.
- `dispatched`: atomically claimed exactly once immediately before HTTP; cannot be released or reused. Its maximum remains outstanding until settlement.
- `reconciled`: valid provider-reported integer input/output usage and locally priced actual cost. Duplicate reconciliation is idempotent. Known cost is never capped to the reservation. Reported overrun fails the application operation and preserves its full known cost, potentially stopping later calls.
- `usage_unknown`: potentially paid call with missing/unusable usage. Actual token fields remain `null`, actual measured cost remains zero, and a distinct `conservative_cost_microdollars` retains the reserved maximum. Monthly accounted spend includes this conservative amount; provider token totals do not invent tokens. Repeated settlement/release cannot erase it. Later known reconciliation is possible.
- `released`: no paid dispatch; contributes neither spend nor successful questions.

Reconciliation occurs in the shared transport **before** status/application text/schema parsing, including malformed summaries, malformed/incomplete output and non-2xx responses that contain usage. Timeout, transport exception, lost response, malformed body or missing usage retains conservative exposure. Pre-dispatch validation, no price, malformed local input, hard-stop denial or failed durable admission causes zero HTTP and no paid charge. Application errors are bounded and do not persist raw transport/provider errors into scanner records.

The $10 warning uses accounted monthly spend, including classifier, scanner and conservative unknown usage. The $20 stop checks reconciled/conservative spend plus every outstanding reservation. Outstanding reservations remain visible across UTC month rollover; settlement and source breakdown use the same resolved-month policy. A deterministic overlapping-admission test proves that the second call cannot consume headroom already reserved by the first.

## Lock and persistence qualification

Native WordPress `add_option` uses an `ON DUPLICATE KEY UPDATE` insert and is not a safe create-only lock primitive after concurrent cache misses. The corrected existing-ledger lock uses a prepared `INSERT IGNORE` into the same non-autoloaded option key and requires exactly one inserted row. Ledger, lock, fault and negative-option caches are invalidated before/across locked work so a primed per-request cache cannot overwrite another request's reservations.

Both the in-memory harness and native disposable WordPress reproduced the lock insertion race in RED. Native SQL injection of a competing lock just before acquisition now must preserve the winner and deny the contender. An occupied lock, including an old one, fails closed. Automatic age-based delete/takeover was removed because a delayed writer may still be active. Storage failure cannot permit dispatch: admission/claim must persist, and a failed ledger write retains its lock.

An unsuccessful post-dispatch settlement additionally raises a durable **incident fence** (`oras_ai_usage_ledger_fault`), created with the same atomic insert-only primitive. This is not a second accounting ledger. It preserves the first failed settlement's reservation ID and reported token pair (or null if unknown), blocks both new reservations and claims, forces hard stop, and marks aggregate data `accounting_available=false`. Usage & Cost explicitly labels totals incomplete and displays “Accounting unavailable.” A known overrun combined with settlement lock contention cannot resume spending after the competing writer releases its lock.

Other requests already in flight can still settle. If several settlements fail concurrently, the fence preserves the first incident metadata; other unresolved dispatched reservations require provider-side recovery. Displayed partial sums are not claimed to be exact spend. The owner must not clear an incident fence merely because the database becomes available again.

Recovery is an operator procedure, not new automated scope: quiesce all paid entry points/workers; establish that no prior lock owner can resume; repair storage; inspect the first incident and every unresolved dispatched record; remove an abandoned lock only after writers are stopped; reconcile available provider usage (or retain conservative unknown accounting); clear the incident fence only after accounting is complete; verify totals and then reopen execution. This intentionally trades availability for protection. No recovery action was performed on production.

## Operator visibility and privacy

The existing Usage & Cost page adds one small table of bounded paid-call source, count and accounted USD, plus an included-unknown-spend subtotal. It retains existing warning/stop display and existing settings; it does not introduce a new dashboard or warning system.

Ledger and incident records contain identifiers, timestamps, bounded source, model/rate snapshot, token/cost values and state only. Tests inspect stored records for absence of member questions, scanner body/title, prompts, answer text, raw provider response and synthetic secrets. No prompt/content logging was added.

## Test-first evidence and regressions

No production edit preceded the initial RED suite. Initial RED: 30 new failures against real adapters with injected HTTP, with the prior 663 passing. Representative failures were missing durable pre-HTTP reservation, unpriced/hard-stop dispatch, missing spend, auxiliary quota charge and four ungated endpoint occurrences. Additional targeted RED runs caught month rollover, oversized domain whitespace, settled-month visibility, failed-overrun settlement contention and native-style lock insertion races before their corrections.

Logs:

- `/tmp/oras-ai-m9-task1-red.log`
- `/tmp/oras-ai-m9-task1-red-additional.log`
- `/tmp/oras-ai-m9-task1-red-month-reporting.log`
- `/tmp/oras-ai-m9-task1-red-contention.log`
- `/tmp/oras-ai-m9-task1-red-lock-race.log`
- `/tmp/oras-ai-m9-task1-green.log`
- `/tmp/oras-ai-m9-disposable-red.log`
- `/tmp/oras-ai-m9-disposable-lock-red.log`
- `/tmp/oras-ai-m9-disposable-green.log`
- `/tmp/oras-ai-m9-disposable-final.log`

The new site-wide suite covers every family at/over $20, missing pricing/invalid model, pre-HTTP persisted reservation and claim, exact output/timeout, known/unknown usage, malformed and HTTP-error usage, overrun, outstanding headroom, failed storage, metadata privacy, warning and source visibility. It also covers real orchestrator classifier+answer/refusal, support service reservation reuse, all domain enums, large scanner extraction, approved knowledge preservation on denial/incomplete/oversized output, UTC rollover, administrator gateway, input bounds, settlement replay and lock/fault races.

The deterministic inventory guard recursively tokenizes production `includes` and the main plugin. It permits the one qualified Responses endpoint and inventories network functions/callable names, including remote GET/POST/request, safe variants, cURL and stream/file functions. Existing AstronomyAPI and NWS GET seams are explicitly enumerated; a new production dispatch requires review and qualification. This is a regression aid, not a proof against deliberately obfuscated code.

Existing real-adapter tests now supply explicit synthetic local prices. Tests that previously expected a raw scanner HTTP/provider error now require a bounded error. Legacy unknown-spend expectations use `usage_unknown` and its conservative field. Three support tests requiring a second question/burst charge were replaced with auxiliary no-question-charge and preserved-hard-stop regressions; all ordinary member quota tests remain intact. No live pricing values were introduced.

Implementer verification: **705 PHP tests and 78 frontend assertions passed** in a fresh `npm test`. The new suite contains **43 M9 tests**; consolidation of obsolete summary quota tests produces a net increase of 42 PHP tests over the 663 baseline. Final coordinating verification is recorded below.

## Disposable integration class

`disposable-contract.php` is a guarded WP-CLI probe for the existing disposable tests site only. The plugin is copied to a temporary directory and loaded directly, not installed/activated. It uses a synthetic key, blocks all outbound HTTP and mail, snapshots options and restores them in `finally`. It checks plugin loading/version, frozen settings, native ledger $20 stop, administrator admission and all four concrete adapters with zero HTTP; it also exercises stale cached ledger state and the native concurrent lock race. This is native disposable WordPress evidence, not production or paid provider evidence.

## Files and scope

Production additions: `includes/class-oras-ai-paid-openai-transport.php`.

Production modifications: ledger, execution controls, answer orchestrator, OpenAI answer/domain/scanner adapters, support summary service, Cost Admin and main plugin bootstrap. No model/config-pricing definitions, source lifecycle implementation, frontend code or version files were changed.

Tests: new `tests/cost/SiteWideAccountingTest.php`; bounded HTTP/database/failure seams in `tests/bootstrap.php`; fixture/semantic updates in access, answer, domain, OpenAI response, answer/source provider, scanner lifecycle and support summary suites. Evidence includes the plan, this report and the disposable contract.

No staging/commit, Task 2+ implementation, idle-retention cron, safe-default kill-switch change, package/ZIP, version bump, production access, deployment, live OpenAI or AstronomyAPI evaluation occurred. Plugin remains `0.2.1`.

## Final coordinating verification

All final requested commands completed successfully against the final production and test changes:

| Command | Result | Log |
|---|---|---|
| `npm run lint:php` | PASS, 207 PHP files, exit 0 | `/tmp/oras-ai-m9-final-lint-php.log` |
| `npm run lint:js` | PASS, both scanner/chat syntax checks, exit 0 | `/tmp/oras-ai-m9-final-lint-js.log` |
| `npm test` | PASS, 705 PHP tests + 78 frontend assertions, exit 0 | `/tmp/oras-ai-m9-final-test.log` |
| `npm run quality` | PASS, 207 / 705 / 78, exit 0 | `/tmp/oras-ai-m9-final-quality.log` |
| `git diff --check` | PASS, exit 0 | Coordinating final inspection |
| `git diff --stat`, `git status --short` | Reviewed; authorized Task 1 production/test/evidence changes only | Coordinating final inspection |

The final test/quality runs still report the existing `class-oras-ai-current-weather-service.php` undefined array keys 6 (line 197) and 3 (line 207). Tests pass; these warnings are disclosed, not represented as a warning-free result.

Independent read-only review found no remaining Important/Critical findings after the atomic-lock/fault-fence correction. The reviewer ran all 43 M9 focused tests successfully. Final native disposable qualification passed all adapter hard-stop, stale-cache and forced concurrent SQL insertion checks, preserved the competing lock token, and restored all four options including the fault marker (`/tmp/oras-ai-m9-disposable-final.log`). Temporary code/probe files were removed and checked absent; the original six-container disposable stack was returned to its stopped state, with unrelated services untouched.

HEAD remains `56b8ba9a9ef19bc856b4f4be92ed6a61196e206f`, ahead/behind `0/0`. No commit or staging occurred. Task 1 has no known remaining implementation blocker and is ready for owner review. No live-model completion-quality, M9 closure or production-readiness claim is made by this qualification.
