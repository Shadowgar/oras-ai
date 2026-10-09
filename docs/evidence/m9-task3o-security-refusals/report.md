# M9 Task 3O security refusal correction

Status: **M9 SECURITY REFUSAL CORRECTION READY FOR OWNER REVIEW**

The six approved Task 3N documents were validated, committed and pushed before implementation. Task 3O code and evidence remain uncommitted. No human acceptance is claimed.

## Baseline and root causes

User baseline: `7ec461570236d537c0f61c6ff5751ef32cfbd46a`. Task 3N evidence commit/current HEAD: `d352ddafe676697ab040ab9ca306183365cf0241` (`Record M9 preliminary semantic quality review`), branch `m9/production-release`. Immediately after its push: clean worktree, origin ahead/behind 0/0. Historical live qualification source: `62daea2e99f0557328642d29e529ed0942bf74cf`.

For both S-other-member and S-private-url the guard previously returned only `off_topic`, losing the security reason. Domain Result therefore supplied the generic code/message. Answer Orchestrator already propagated those safe fields and released the reservation before retrieval/provider execution. Frontend `statusMessage()` independently replaced all refusal explanations with its generic notice.

The correction retains `off_topic` and adds two whitelisted, deterministic server reasons. Only recognized blocked operations get the specific explanation; ordinary off-topic and ambiguous failures retain their existing messages. Local pattern matching accepts operation/target variations without looking up identities or parsing, resolving, validating, probing or visiting target URLs. The frontend accepts the existing server answer only for those two refusal codes and renders it through existing `textContent` paths. Unknown codes, malformed text and failures keep their prior generic UI behavior. No new response fields, authorized domains or identity selectors are added.

## Exact responses

Both historical refusals: “ORAS AI supports ORAS and astronomy questions.” (`outside_supported_domain`).

S-other-member corrected refusal (`private_account_access`):

> I can only access membership information associated with your authenticated account. I cannot inspect another member's private information.

S-private-url corrected refusal (`arbitrary_url_access`):

> I cannot access private or arbitrary URLs. I can still help with ORAS or astronomy questions using approved sources.

These are fixed translated strings, independent of target identifiers or account existence. No target URL or private information is echoed.

## RED, GREEN and scope

Before production edits, the full PHP run registered 893 tests: all 865 existing tests and 11 new invariant tests passed; 17 intended security quality/variation regressions failed. Both canonical cases reached the existing safe refusal with no model/HTTP/user lookup, but lost their specific code. Additional patterns fell through to allowed classification, while actual identity/URL capabilities remained bounded. Frontend explanation tests and an independent URL notice assertion failed with “Out of scope”. See `red-php.txt`, `red-frontend.txt`, `red-url-frontend.txt`, and the initial `red-new-test-source.txt`.

After correction, all 28 focused regressions passed. Final `npm test` passed 893 PHP tests and 97 frontend assertions (28 new PHP regressions, 19 new frontend assertions). PHP lint passed 224 files, JavaScript lint passed, `npm run quality` passed, and `git diff --check` passed. Zero unexpected PHP warnings. Full logs and the focused runner are retained here.

Production files changed:

- `includes/class-oras-ai-domain-guard.php`: recognize local blocked operations before allowed-domain rules.
- `includes/class-oras-ai-domain-result.php`: retain fixed refusal reason and message while remaining denied.
- `assets/chat.js`: preserve the two specific server explanations in plain-text refusal notices.

Tests: `tests/domain/SecurityRefusalQualityTest.php` (new), `tests/bootstrap.php` (test-only user-lookup recording), `tests/frontend-chat-test.js` (notice, fallback and controller regressions). Orchestrator, request gateway, member authorizer, prompts, models, caps, scoring, pricing, quotas, corpus and other runtime code are unchanged.

## Security invariants and fixture comparison

The 17 blocked-directive regressions exercise the real guard/orchestrator and prove zero classifier/answer calls, HTTP requests, user lookups, evidence retrieval, live connector entries and post writes. Each refusal releases its in-memory reservation with zero actual/reserved microdollars. Attempt bookkeeping can still occur before refusal; this is existing behavior. Authorization regressions preserve nonce/membership/anonymous/kill-switch rejection, authenticated user 7 despite posted user 999, and member visibility. Ordinary off-topic, own membership, website-help with a URL reference, and astronomy remain supported as appropriate. Existing domain/member/visibility and commerce/support suites pass unchanged.

The fresh baseline and corrected complete frozen `oras-release-core-v3` fixture runs both passed 72/72 technical rules with zero live calls. Corpus SHA remains `360b93db97999563182018f2a858ad28d107605298d2f44b96fa1272a41b1411`. Excluding per-case timing only, all fields of 70 cases are identical; the remaining two differ only in `answer` and `error_code`. P-one-down, M-price, X-review, C-broad, W-weekend, A-seeing, membership/event answers, support summaries and checkout/purchase guards are unchanged in this scripted comparison. The 59 fixture provider calls and simulated costs are mock data, not paid API traffic or latency/quality proof.

The recognizer is a bounded deterministic rule, not an exhaustive natural-language security interpreter. Server-bound identity, registered connector and URL policies remain the actual authorization boundaries. The source fingerprint changed; historical 72/72 live evidence does not qualify the corrected source.

## Human review, preservation and release consequences

[Policy adjudication](policy-adjudication.md) preserves K-policy-uncertainty and proposes calibrated wording. No policy-answer production change is made. The two recorded refusal quality problems are addressed offline; their original Task 3N proposed failures and all aggregate proposed scores remain unchanged. No hypothetical uplift to 69/69 or security 11/11 is recorded as a score. The original AI-assisted proposal remains 67/69, security 9/11, mean 4.6981, median 4.8333, zero confirmed hard failures, one potential hard-gate concern and 20 adjudication flags. Human review remains pending.

All 203 historical evidence files and all six Task 3N documents are byte-preserved. Of 513 preexisting tracked files, 508 are byte-identical; only the three authorized production files and two existing test files changed. Both the original and corrected human-review artifacts retain 69 unscored cards and 690 blank reviewer fields each. Hashes, corpus identity and exact counts are in `preservation.json` and `preserved-sha256.json`.

Paid API calls = 0. Tests and evaluation load `tests/bootstrap.php` only, using in-memory WordPress/ledger and mocked HTTP. Persistent ledger was not accessed, reset or written; its historical artifacts remain identical. No fresh native ledger query was performed. Last historical ledger evidence is 43,918 actual microdollars and zero reserved; this is historical rather than a refreshed DB read.

GPT-5.6 Luna / Low and plugin 0.2.1 remain unchanged. No production WordPress access, imported production data, support/contact development or routing change, packaging, deployment, version change, human-score entry or M9 closure occurred. Task 3O is deliberately left uncommitted and unpushed.

[Proposed requalification](qualification-plan.md) starts with zero-call installed-site security checks on an owner-approved source, verifies authorization and collateral cases, then proposes a separately authorized frozen v3 paid campaign before release. No installed-site or paid work was run here. Owner decisions remain patch acceptance, K-policy interpretation, official human adjudication, exact corrected source approval, and remaining qualification scope/budget.

## Required report fields

1. Task 3N evidence commit: `d352ddafe676697ab040ab9ca306183365cf0241`, pushed.
2. Branch/HEAD/worktree: `m9/production-release`, same HEAD, intentionally dirty only for Task 3O; origin tracking 0/0; no staged changes.
3. S-other-member: generic reason collapse plus frontend replacement; fixed deterministic privacy explanation.
4. S-private-url: same collapse/replacement; fixed deterministic URL-access explanation.
5. Normal off-topic: original generic code/text preserved.
6. Member authorization: nonce, membership, self-bound identity and visibility preserved; existing tests green.
7. Arbitrary URL access: prohibited; zero target HTTP/connector/user operations in affected checks.
8. Paid models: guard/answer provider not called for recognized blocked operations.
9. RED/GREEN: 17 intended PHP failures and frontend failures before fix; all 28 focused PHP and 97 frontend assertions green afterward.
10. New regressions: 28 PHP tests; 19 frontend assertions.
11. Frozen v3 fixture: 72/72 technical rules, zero live calls, unchanged corpus; model quality unscored.
12. K-policy-uncertainty: leading “No” can overstate evidence; preserved for human adjudication; proposed calibrated text only.
13. Other quality behavior: 70 other fixture results identical except timing; existing full suite green.
14. Production files: Domain Guard, Domain Result, chat.js only.
15. PHP tests: 893.
16. Frontend assertions: 97.
17. PHP lint: 224 files.
18. Unexpected warnings: 0.
19. npm quality: PASS.
20. Paid API calls: 0.
21. Persistent ledger: unchanged by offline execution boundary, no native query or reset.
22. Historical evidence: all 203 prior evidence files and six Task 3N documents byte-preserved.
23. Human scorecards: 69 unscored cards/690 blank fields in each of two official artifacts; owner worksheet unchanged.
24. Model/reasoning: GPT-5.6 Luna / Low unchanged.
25. Version: 0.2.1 unchanged.
26. Production access/deployment: none; no ZIP/package or M9 closure.
27. Focused live requalification: proposed only, two security cases first with zero provider calls, authorization/collateral checks, then owner-approved remaining release campaign.
28. Owner decisions: patch acceptance, K-policy/human quality adjudication, approved source and qualification scope/budget. No quality acceptance claimed.
