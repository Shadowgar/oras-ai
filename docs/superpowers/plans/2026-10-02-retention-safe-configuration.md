# M9 Task 2 Retention and Safe Configuration Plan

> Execute with superpowers:subagent-driven-development and test-first discipline. User authorizes continuous implementation. Task 2 MUST remain uncommitted. Do not repeat the M1-M8 architecture audit.

**Goal:** Enforce usage metadata retention through native WordPress scheduled maintenance and prepare safe installation/configuration visibility without changing runtime models, prices, enablement defaults or release scope.

**Spec:** `/home/rocco/.codex/attachments/74826ee1-0c34-4ffc-a44d-f85ed1271d32/Pasted text.txt` (full user request, mandatory). Read only named relevant contracts.

**Checkpoint:** Approved Task 1 committed and pushed as `ea1f9ea8e0d29b8b1703af68d960682d93405181`, message `Close site-wide OpenAI budget gaps`; clean worktree and ahead/behind0/0 verified before this plan. Fresh quality207 PHP files/705 tests/78 frontend pass. Existing weather warnings lines197/207 disclosed. Only Task1 commit authorized; no Task2 stage/commit/push.

## Governing requirements and constraints

- NFR-PRIV-001: 30-day conversation text; twelve calendar months usage metadata, no full conversation text. NFR-PRIV-002 external processing disclosure; pending escalation local30-day lifecycle; Fluent Support retention separate with no invented numeric period.
- ADR-0019, existing privacy/retention policy and decision003, cost control and safe enablement/operator docs. Preserve exact current cutoff `strtotime('-12 months', now)` with strict older-than comparison, including PHP leap/month-end behavior unless proven correctness requires change.
- Preserve 25/day,150/month,5/minute; $10 warning/$20 stop; Task1 call caps, source precedence and support confirmation/replay/uncertain state. Version0.2.1, runtime model/reasoning/prices unchanged. Missing-option member enablement default unchanged.
- No package/deploy/production/paid providers/other repo edits/new scheduler framework/Task3/M9 closure. No external hosting scheduler or wp-config changes.

## Existing flow and proposed integration

Existing ledger prunes during reserve/rejection, summary and explicit prune; explicit mutations use Task1 atomic INSERT IGNORE option lock, refreshed caches, durable accounting failure fence. Current prune simply removes old reservation records, which must be corrected so maintenance cannot reopen outstanding budget headroom or erase current spend. Conversation/escalation classes use daily native WP-Cron, init registration, idempotent wp_next_scheduled and hook-specific deactivation.

Use one daily named usage-cleanup event with the same conventions, registration on bootstrap/init and activation (existing same-version installations need it too), dedicated deactivation removal, and no data wiping on activation/reactivation. Callback calls the same ledger-safe retention path. Bound mutation/processing per maintenance invocation without introducing a second ledger; be explicit about unavoidable whole-option load/store costs. Record only bounded maintenance status/timestamps/counts, show scheduled/last success/busy safely on existing Usage & Cost.

Expired settled records normally delete; expired unresolved/current-spend/recovery-required records must lose member identity and unnecessary personal timing metadata while retaining only non-content accounting/recovery fields needed to keep exposure and late settlement safe. Preserve accounting-fault state. Consider minimal redacted records within the existing ledger instead of a parallel ledger. Exact representation and bounded batch policy may be refined from code; document rationale. Stop only if a genuine frozen schema/retention contradiction cannot be resolved with this small treatment.

Readiness is local configuration only, using existing Settings/Usage/Provider/Support surfaces, no universal score or new dashboard. Check key presence, raw allowlisted model and valid matching price, accounting fault/lock state, AstronomyAPI pair validity, NWS contact, fixed site, local support routing format and General/topic fallback, existing contact URL, member kill state, schedule/maintenance. Do not fetch/validate authentication remotely or expose raw secrets/private routing in new summaries. Preserve established price validation including its treatment of zero rates; no invented free model or prices.

Safe procedure: install inactive, explicitly preseed `oras_ai_member_ai_enabled='0'` BEFORE activation, activate/configure/qualify, owner-authorized enablement. Do not set defaults on upgrades. Document system scheduler WP-CLI invocation using operator-supplied authorized account/path, checks and cadence/delay; installed external scheduler remains deployment verification. Inactive plugins do not enforce hook-based retention; operator responsible for retained data.

## Files

Likely production: existing usage ledger, small usage-maintenance class, plugin bootstrap/activation/deactivation, Cost Admin and existing Settings readiness helper/rendering as minimally needed, chat disclosure, support routing local validation if required. Tests: focused retention/scheduling/configuration/safe-install tests plus narrow fixtures in bootstrap. Docs: amend existing operations/privacy docs and add concise Task2 evidence. Root owns disposable contract file separately; implementer owns remaining production/tests/docs.

### Task 1: Task 2 coherent implementation

- [x] Read full spec and relevant contracts/code only. Confirm minimal design and safe-install test mapping before changes.
- [x] Write focused tests and observe RED before production edits: cutoff exact/younger/old; leap/month-end; idle callback; schedule duplicate prevention, same-version upgrade, deactivation/reactivation; idempotence; bounded batches; lock contention/fresh reservation and settlement; expired unresolved redaction; current spend/quotas/fault preservation/zero HTTP; seeded OFF, savedON/OFF upgrade, consoleOFF, scanner budget separate; safe local readiness/secret absence/no live-verification claim/disclosure.
- [x] Implement minimum scheduler + ledger-safe privacy-preserving retention, bounded maintenance outcome, readiness/disclosures and concise operator procedure. No Task1 semantic regressions.
- [x] Run focused tests during development (include Task1 M9 regressions); do not run the full quality suite repeatedly. Root runs final `npm run quality` ONCE after review and disposable qualification. Test-first newly found bugs.
- [x] Write `docs/evidence/m9-retention-safe-configuration/verification.md` and `/tmp/oras-ai-m9-task2-implementation-report.md`, exact changed files, RED/GREEN, limitations/decisions. Notify root when code stable for review/native test.
- [x] Independent review + root native WordPress cron qualification + final single quality + diff/status checks. Keep Task2 uncommitted for owner review.

## Review focus

Expired unresolved and very late settled calls must not lose cost protections when member identifiers expire. First-fault references must survive safely without retaining old personal metadata. Scheduled cleanup is bounded and doesn't skip old buckets forever. No callback calls providers. Same-version install scheduling works without version bump. Configuration status never asserts live authentication or external scheduler proof. No unrelated files in the already-recorded Task1 commit or subsequent Task2 changes.

Preflight: one implementer owns coupled ledger/scheduler/config interfaces; root owns native disposable probe/final verification. Existing clean dedicated requested branch is the workspace; no new worktree or history rewrite.
