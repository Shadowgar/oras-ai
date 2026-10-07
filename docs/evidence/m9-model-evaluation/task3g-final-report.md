M9 TASK 3G — FINAL OWNER REVIEW REPORT (2026-10-07)

**ADVANCE GPT-5.6 LUNA ONLY** for the next evaluation stage. This is specialized disposable evidence; full-corpus qualification and production readiness remain open.

1. Task 3F evidence commit: `99fe42df2fdab94ddc607aa0378a3c4314a99212`, pushed; clean immediately afterward. Historical v2 9/10 runs and raw outputs unchanged.
2. Requirements audit: no explicit M0/M2 requirement that review flags must both be true. KB-007/008/012/014 require classification, separation, safe autoapproval and live-fact exclusion; review lifecycle remains mandatory.
3. Validator contradiction: YES. Prompt routes unresolved safety to review; previous validator rejected either false Boolean flag for every class.
4. Exact production fix: only two false-value rejection branches now require `source_kind != review`; missing/non-Boolean flags and all other validation remain enforced.
5. RED/GREEN: 12 review tests, four expected failures before fix, 12/12 after; review plus corpus integrity 24/24. All four review Boolean combinations valid; missing/type/fragments invalid; false flags invalid for all four other classes.
6. Lifecycle safety: valid high-confidence review requires a human, cannot autoapprove, extracts no durable fragments, demotes existing scanner-approved artifact to review and is absent from active approved retrieval. Review source body may persist only for the review queue.
7. Production prompt changed: NO.
8. Production schema changed: NO.
9. V2 SHA-256: `daa9216b505c7be0206145b0bb2cbaf8b129cec18e0e93a6dd921720914b2f98`.
10. V3 SHA-256: `360b93db97999563182018f2a858ad28d107605298d2f44b96fa1272a41b1411`.
11. Model-visible content unchanged: YES. Exact corpus changes only `/version` and `/cases/id=X-review/evaluation_notes`; all 72 prompts and scanner source fields preserved. New fixture flags false/false exercise corrected semantics.
12. Offline GPT-6 retained-output replay: 10/10 schema, expected label and application-valid, using original raw outputs.
13. Offline GPT-5.6 retained-output replay: 10/10 on the same dimensions.
14. Paid calls before retained replay: 0.
15. Contract-fix commit: `439c93eee5450453124fcc7f7bed901dc9ac0386`, pushed; clean and origin0/0 before fresh calls. Scoped independent reviewer found no material issue.
16. PHP tests: 805 passed.
17. Frontend assertions: 78 passed.
18. PHP lint: 220 files passed.
19. Warnings: 0 in fresh verification and native execution.
20. Fresh GPT-6 domain: 5/5.
21. Fresh GPT-6 scanner: raw labels5/5; captured API schemas5/5; applied labels4/5. X-utility returned ignore with false/false flags and correctly fell back to invalid review.
22. Fresh GPT-6 scanner application-valid: 4/5; specialized overall9/10. X-review is now valid.
23. Fresh GPT-6 tokens/cost/latency: 7595 input, 1172 output, 4936 cached input; accounted $0.001352; provider total 38.397s, median 3.796s, p95 4.930s.
24. Fresh GPT-5.6 domain: 5/5.
25. Fresh GPT-5.6 scanner: raw labels5/5, captured API schemas5/5, applied labels5/5.
26. Fresh GPT-5.6 scanner application-valid: 5/5; specialized overall10/10.
27. Fresh GPT-5.6 tokens/cost/latency: 7595 input, 924 output, 4936 cached input; accounted $0.002638; provider total 24.482s, median 2.370s, p95 4.070s.
28. Temporal-reason issue preserved: YES, **HUMAN SEMANTIC REVIEW ITEM**. Both retained v2 reasons remain unchanged; fresh GPT-6 repeats the unsupported future-date assertion. Fresh GPT-5.6 states approval uncertainty without that assertion. Source/prompt unchanged, no human quality scores invented.
29. Provider failures/timeouts/truncations: 0/0/0. No technical or safety failure; zero external ticket, mail, payment or production action.
30. Cumulative disposable ledger: $0.022116, 118 calls, 57318 input/11479 output tokens. Task3G added20 paid calls and $0.003990; no paid preflights/retries. Historical unknown-accounting amount$0.000385 unchanged; new unknown usage0.
31. Outstanding reservations: 0; all20 paid calls reconciled.
32. Production default unchanged: gpt-5.6-luna/Low. Saved model override still unset; saved controls and persistent guard hashes unchanged. Candidate overrides were process-only.
33. Version remains0.2.1; no package/deployment/version bump.
34. Full LIVE corpus run: NO. Automated offline fixture coverage remains72 cases.
35. Candidates advancing: **ADVANCE GPT-5.6 LUNA ONLY**.
36. Recommended next action: owner reviews fresh evidence and temporal semantic item, then decides whether to authorize the full v3 corpus for GPT-5.6 Luna/Low. GPT-6's non-review false flags remain a qualification failure; no tuning or contract expansion is proposed here.

All ten model-independent request hashes match both retained v2 runs. Caps remain domain128/scanner12000, timeouts20s/60s; identical source commit, prompts, schema, source and thresholds across candidates. Accounted costs use the established conservative local rates without cached-token discounts and are not provider invoices. Latency p95 uses linear interpolation across10 calls.

Both disposable test containers are stopped again; volumes and ledger preserved. Fresh live reports, source proof, ledger snapshots and this report remain uncommitted for owner review. Branch remains synchronized0/0; the worktree intentionally contains fresh evidence only.

Evidence: `v3-specialized-ab-results.json`, both `v3-*-specialized-results.json`, `task3g-ledger-after.json`, `task3g-source-proof.json`, `task3g-retained-output-replay.json`, `task3g-verification-checks.json`.
