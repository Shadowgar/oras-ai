# M9 local baseline closure

Qualification date: 2026-10-05. Scope: preserve the approved Task 2 work as its
own commit, correct optional weather-time captures, fail unexpected test-time
PHP warnings, and reconcile current roadmap status. M9 production release
remains in progress.

## Starting checkpoint and Task 2 inventory

Branch: `m9/production-release`. Starting HEAD:
`ea1f9ea8e0d29b8b1703af68d960682d93405181` (`Close site-wide OpenAI budget gaps`).
After fetching origin, ahead/behind was **0/0**. Plugin header, runtime constant,
and package version were **0.2.1**.

The original tracked diff was ten files, **229 insertions / 36 deletions**.
The complete original status was:

```text
 M docs/operations/cost-control.md
 M docs/operations/operations-runbook.md
 M docs/security/privacy-and-retention.md
 M includes/class-oras-ai-chat-ui.php
 M includes/class-oras-ai-config.php
 M includes/class-oras-ai-cost-admin.php
 M includes/class-oras-ai-sources.php
 M includes/class-oras-ai-support-routing.php
 M includes/class-oras-ai-usage-ledger.php
 M oras-ai-assistant.php
?? docs/evidence/m9-retention-safe-configuration/disposable-contract.php
?? docs/evidence/m9-retention-safe-configuration/verification.md
?? docs/superpowers/plans/2026-10-02-retention-safe-configuration.md
?? includes/class-oras-ai-usage-maintenance.php
?? tests/config/ReleaseReadinessTest.php
?? tests/cost/RetentionMaintenanceTest.php
```

All sixteen files matched approved Task 2 retention, scheduling, local readiness,
privacy, operator documentation, tests, plan, and evidence. No unrelated or
ambiguous changes were found. Nothing was reset, reverted, stashed, or dropped.

Fresh `git diff --check` and `npm run quality` passed before staging: **211 PHP
files linted, 721 PHP tests, 78 frontend assertions**, with precisely the two
inherited weather warnings. Log: `/tmp/oras-ai-m9-baseline-task2-quality.log`.
Only the sixteen listed files were staged; cached whitespace check passed.

Task 2 was committed as **`97c9a707e536c45c5c51f174f43abbb3a38c8358`**, message
`Finalize production configuration and retention`, and pushed successfully to
`origin/m9/production-release`. The resulting tree was clean and ahead/behind
**0/0**, before any weather edits. Its staged inventory was sixteen files,
754 insertions / 36 deletions. The previous disposable WordPress 31-check result
remains historical evidence; no disposable-site rerun or production access was
performed during this local baseline task.

## Weather root cause and minimal correction

The time regex is `(\d{1,2})(?::([0-5]\d))?\s*(am|pm)?`, embedded in point
(`at TIME`) and range (`from TIME to TIME`) patterns. Minutes and meridiem are
optional. PHP omits trailing unmatched captures: `cloudy at 21:30` produces
groups 1 and 2 but no group 3; `forecast from 20:30 to 23:15` has no group 6.
Minute groups can also be absent in suffix-free, hour-only input. Direct array
accesses passed these missing values to `local_time()` and emitted warnings.
The original runner caught exceptions only, so warnings did not affect exit
status or test success.

The production correction changes only the three `local_time()` calls to use
`?? ''` for optional minute/meridiem groups. Regexes, date selection, timezone,
validation, tonight fallback, and next-day rollover remain unchanged.

Permanent regressions cover both original strings, AM/PM with and without
minutes, 24-hour time, mixed minute presence, reversed overnight ranges,
tomorrow, named weekday, explicit date, invalid hours, and unsupported bare
hours. Existing full-suite default-night and DST regressions also pass.

## Warning-sensitive runner and RED/GREEN evidence

The actual `tools/run-tests.php` is copied into a temporary minimal test
repository for isolated subprocess tests; fixtures and directories are removed
in `finally`. No permanently failing fixture is registered in the normal suite.

Before implementation, the focused run was **8 passed / 12 failed**, exit 1.
The two original weather cases failed on missing groups 3 and 6. Runtime
`E_WARNING` and `E_USER_WARNING` fixtures printed warnings, reported PASS, and
exited **0**, demonstrating the old harness gap. Log:
`/tmp/oras-ai-m9-baseline-red.log`.

The runner now installs a per-test handler for only `E_WARNING | E_USER_WARNING`.
It records each warning and throws `ErrorException`; a recorded warning also
fails the test if application code catches that exception. The previous handler
is restored in `finally`. Tests can temporarily override and restore the handler
to assert an expected warning. Deprecation handling and startup/load behavior
outside test callbacks are unchanged.

The original twenty focused cases passed after correction; an additional AM
case was then included in the full suite. Isolated warning fixtures now exit
**1**, identify `FAIL warning fixture`, and report one failed test. Separate
cases cover a caught warning, explicitly handled warning, unchanged deprecation
policy, and caller-handler restoration after success and failure.

Fresh precommit verification: **212 PHP files linted; 742 PHP tests and 78
frontend assertions passed; zero unexpected PHP warnings**. Logs:
`/tmp/oras-ai-m9-baseline-precommit-lint.log` and
`/tmp/oras-ai-m9-baseline-precommit-test.log`.

Direct reproductions used the fixed clock `2026-09-09T12:00:00Z` and ORAS
`America/New_York` timezone, with zero warnings:

| Question | Local interval | UTC interval |
|---|---|---|
| `cloudy at 21:30` | Sep 9, 21:30 EDT point | Sep 10, 01:30 UTC point |
| `forecast from 20:30 to 23:15` | Sep 9, 20:30–23:15 EDT | Sep 10, 00:30–03:15 UTC |

## Roadmap and remaining release gates

Only current roadmap wording was reconciled: M8 closure documentation was
committed at `56b8ba9`; M9 explicitly remains in progress and records the Task 1
and Task 2 commit checkpoints. Historical M8 qualification evidence is unchanged.

Remaining M9 gates: security review, production privacy/retention communication,
tested backup/rollback, operational budgets/alerts, accepted model evaluation
thresholds, monitoring/kill-switch proof, verification that anonymous AI remains
disabled, and owner acceptance of production evidence. Installed integrations,
credentialed providers/support, production idle scheduler execution, and browser
qualification remain deployment qualification work.

No packaging, ZIP, deployment, production access, model/pricing change, version
bump, evaluation, backup/rollback implementation, browser qualification, or M9
completion is included. The final postcommit command results and second commit
hash are reported in the handoff, avoiding a self-referential commit hash here.
