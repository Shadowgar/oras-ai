# M9 Task 2 — Retention and safe configuration

Qualification date: 2026-10-02. Task 2 remains **uncommitted for owner review**. This evidence does not authorize packaging, deployment, production access, paid calls, Task 3 or M9 closure.

## Approved checkpoint and governing contracts

Root verified, committed and pushed approved Task 1 as `ea1f9ea8e0d29b8b1703af68d960682d93405181` (`Close site-wide OpenAI budget gaps`), with clean worktree/ahead-behind 0/0 before Task 2. Its fresh checkpoint qualification passed 207 PHP lint files, 705 PHP tests and 78 frontend assertions. Historical Task 1 evidence is preserved as written; this paragraph records the subsequent approved commit.

Task 2 follows NFR-PRIV-001/002, ADR-0019, `docs/security/privacy-and-retention.md`, M0 decision003 and existing cost/enablement contracts. The implementation plan is `docs/superpowers/plans/2026-10-02-retention-safe-configuration.md`; the full owner request was read before production edits. Relevant ledger, Task 1 fault/locking, conversation/escalation cleanup, bootstrap, settings and operations code were inspected without repeating the M1–M8 architecture audit.

## Implemented behavior

- Native `oras_ai_prune_usage` daily event; idempotent activation and ordinary `init` registration, including existing same-version installations. Hook-specific deactivation, data-preserving reactivation. No version bump or scheduler framework.
- Existing ledger `prune()` remains a boolean API, backed by `prune_batch()` under the same atomic `INSERT IGNORE` option lock, option-cache refresh and failed-storage safety behavior used by paid execution. Activity pruning continues.
- Strict timestamp cutoff remains PHP `strtotime('-12 months', now)`. Exact-cutoff reservations remain; leap-day normalization and month-end cases are tested. Rejection buckets retain the cutoff month. Burst expiry retains its rolling-minute behavior.
- Each batch examines at most 100 reservations, 100 rejection-month entries and 100 burst timestamps, and visits at most 100 user buckets per ancillary category. Retained records/buckets rotate to avoid starving later expired data. Whole-option loading/serialization remains unavoidable in this existing schema; memory/storage size is not claimed bounded.
- Expired settled records normally disappear. Expired open/dispatched/unknown-usage recovery, current-month settlement and first-fault-reference records retain a whitelist of non-content accounting fields in the same ledger. Member identity, quota association and exact request/dispatch times are removed; resolution is reduced to settlement month. Subsequent late settlement does not recreate an exact personal timestamp. Measured/conservative spend, open exposure, price snapshots and first-fault references remain usable. Unknown usage stays unknown. Once reconciled and past-month without a fault reference, a record can be removed by a later batch.
- Bounded non-content status in `oras_ai_usage_maintenance`: outcome, attempt/success timestamps and examined/removed/redacted counts. Busy cleanup leaves ledger and competing lock intact. Failed ledger storage leaves paid execution blocked. Maintenance does not clear the fault fence.
- Existing Settings shows local configured/missing/invalid prerequisites; Usage & Cost shows maintenance schedule and last successful **batch**. Raw model validation avoids concealing invalid stored models behind the default. Pricing delegates to existing validation, including its rejection of zero rates. AstronomyAPI pair/NWS format and General/topic fallback validation remain local; no credentials, private routing IDs or provider authentication claims appear in new summaries. Provider/support/contact readiness is explicitly not live-verified.
- Shared chat wording distinguishes external processing, 30-day conversation/local escalation records, twelve-calendar-month usage metadata without conversation text and separate support-ticket retention. The privacy document explains non-personal recovery-state preservation, with no invented Fluent Support numeric retention.

Unchanged:25/day, 150/month, 5/minute member limits; $10 warning/$20 stop; Task 1 paid-call caps/accounting semantics; saved runtime model/reasoning/prices; source precedence; support confirmation/replay/uncertain handling; plugin/package version 0.2.1.

## Test-first evidence

Focused runner loaded repository test fixtures and selected Task 1 M9 accounting, new Task 2, relevant execution, kill-switch and privacy tests; it did not run the full quality bundle repeatedly.

1. Before production edits,59 passed / 5 failed. Expected RED failures: expired open exposure deleted, first-fault recovery reference deleted, unbounded pruning beyond the first 100-record window, missing maintenance callback, missing activation schedule. Existing calendar-cutoff cases already passed. Log: `/tmp/oras-ai-task2-red.log`.
2. Core implementation:64 passed/0 failed, including all 43 existing Task 1 M9 regression. Readiness tests were then added before readiness code:64 passed/3 expected failures for missing local readiness/rendering. Log: `/tmp/oras-ai-task2-readiness-red.log`.
3. Disclosure test failed on missing escalation/usage/ticket distinctions before its wording change. Additional checks covered failed storage, bounded ancillary buckets, actual older-version and same-version upgrade paths, scanner OFF/shared budget and seeded OFF console authorization. A draft console test incorrectly expected the legacy console form to be absent; inspection showed rendering is intentionally separate from server-side authorization. The test was corrected to assert the actual kill-switch authorization boundary, with no new UI bypass or behavior change.
4. Final implementer focused result: **72 passed/0 failed**. There are **16 new Task 2 tests**. Log: `/tmp/oras-ai-task2-green3.log`. Independent reviewer also ran all 16 new cases successfully and reported no Important/Critical production finding.

Coverage includes strict old/exact/young cutoff; leap year/month end; idle callback; daily duplicate prevention; same-version ordinary init scheduling; actual 0.2.0 upgrade preserving saved ON and OFF; preseeded OFF activation and reactivation; missing-option default unchanged; idempotent accounting totals; bounded rotating reservations/rejections/burst; unavailable lock and unchanged writer state; fresh settlement/quota preservation; failed storage retained lock; expired unresolved redaction and late settlement; fault-reference retention/fail-closed admission; zero HTTP; valid/invalid local configuration, secret absence and capability protection; scanner separation with shared hard stop. All 43 Task 1 paid-path, month-boundary, contention, unknown-usage and hard-stop regressions remain in the focused selection.

## Disposable native WordPress evidence

Root inspected `/home/rocco/projects/oras-wp-env`, preserved its existing configuration/data and unrelated three untracked entries, and used the isolated tests site. Initial stack state was stopped. Exact test site location was verified locally before mutation; no production access occurred.

The executable [disposable contract](disposable-contract.php) passed **31 native assertions** with actual plugin activation and due WP-CLI cron execution before AI admission or Usage & Cost access. It verified one daily event, recurrence retained after due execution, old removal/current retention/expired-unresolved redaction, preserved current spend/exposure/quota, batch status/idempotence, occupied lock, direct-SQL fresh settlement despite stale option cache, durable fault preservation, deactivation/reactivation OFF, unchanged provider/account configuration, and **zero HTTP/mail attempts**.

Final log: `/tmp/oras-ai-m9-task2-native-final.log`. The contract restored raw values and autoload flags of all nine changed options in `finally`. Temporary plugin/probe files were removed. `wp-env stop` returned exit 0, the project's filtered container list was empty, `.wp-env.json` was unchanged, and the original three untracked entries remained. Stop log: `/tmp/oras-ai-m9-task2-wp-env-stop.log`.

An initial harness experiment forced a future recurring event and encountered WP-CLI reschedule-before-unschedule behavior at the same timestamp. The contract was corrected to mark only its test event due before each run; final qualification uses actual due-event behavior. Operator backlog guidance invokes the callback explicitly without changing the recurring event.

This proves **A: callback behavior** and **B: native schedule registration/execution in the disposable site**. It does **not** prove **C: a production host scheduler runs cron without site traffic**.

## Operator and deployment limits

The operations runbook now requires inactive installation, explicit `oras_ai_member_ai_enabled=0` **before activation**, configuration/qualification and owner-authorized enablement. Activation/upgrades preserve saved ON/OFF; the historical missing-option default remains unchanged. The admin console obeys the same server-side switch. Scanning remains separate from that switch while paid scanner work obeys the shared ledger.

The runbook documents an operator-supplied authorized account/path and `wp --path="$WP_PATH" cron event run --due-now`, a suggested five-minute host invocation cadence, schedule/status verification and finite manual backlog batches. No host scheduler or `wp-config.php` is installed here. First usage maintenance is scheduled about one day after registration. Daily bounded rotation, retained young/recovery entries, growing backlog, lock contention and scheduler outages can add multiple days; neither exact-cutoff-second nor 24-hour deletion is promised. Last success means one successful batch only. Inactive plugins cannot be assumed to enforce retention; the operator must handle retained data and reactivation backlog.

Remaining owner/deployment decisions: production model/release version, provider authentication and support/contact qualification, host scheduler installation and idle execution evidence, backup/rollback and other later release gates. No known Task 2 implementation blocker; no M9 completion or production-readiness claim.

## Changed files and final coordinating verification

Production: `oras-ai-assistant.php`; `includes/class-oras-ai-usage-ledger.php`; new `includes/class-oras-ai-usage-maintenance.php`; `includes/class-oras-ai-config.php`; `includes/class-oras-ai-support-routing.php`; `includes/class-oras-ai-sources.php`; `includes/class-oras-ai-cost-admin.php`; `includes/class-oras-ai-chat-ui.php`.

Tests: new `tests/cost/RetentionMaintenanceTest.php`, `tests/config/ReleaseReadinessTest.php`. Docs: amended operations runbook, cost-control and privacy-and-retention; new Task 2 implementation plan, this evidence and root-owned native contract. No test bootstrap changes or new dependency.

Root ran the single final `npm run quality` after code stabilized: **exit 0; 211 PHP files linted, 721 PHP tests passed, 78 frontend assertions passed, and JavaScript syntax checks passed**. The existing weather-test warnings at lines 207 (key 3) and 197 (key 6) remain disclosed and unchanged. Log: `/tmp/oras-ai-m9-task2-final-quality.log`. Root also verified `git diff --check`, `git diff --stat`, `git status --short`, Task 1 upstream ahead/behind 0/0 and unstaged Task 2 state. Task 2 remains uncommitted for owner review.

Final independent review of production code, tests, operator notes and evidence found no remaining Critical/Important findings or Task 2 implementation blocker. Review report: `/tmp/oras-ai-m9-task2-review.md`; the reviewer independently ran the 16 new tests and inspected the final quality/native logs without duplicating those suites. Final inventory: ten modified tracked files and six new files; nothing staged.
