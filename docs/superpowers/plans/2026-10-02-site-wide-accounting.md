# M9 Task 1 Site-wide OpenAI Accounting Implementation Plan

> **For agentic workers:** Use superpowers:subagent-driven-development. The user explicitly authorizes continuous implementation, forbids commits, and requires RED before production edits. Those instructions override skill approval/commit defaults.

**Goal:** Account every paid OpenAI dispatch against the existing site-wide monthly budget while keeping member question quotas separate.

**Architecture:** Reuse the M3 ledger, pricing and lock. Add one qualified Responses HTTP boundary that requires a persisted reservation, bounds input/output/time, and reconciles usage before application response parsing. Existing member admission remains the quota owner; domain and scanner calls and auxiliary support summaries do not consume additional questions. Unknown usage retains conservative cost with unknown actual tokens.

**Tech Stack:** PHP / WordPress; existing PHP test harness, injected HTTP; JavaScript syntax and frontend assertions.

**Spec:** User attachment `/home/rocco/.codex/attachments/ba4e9f45-754e-4924-bfff-2d5c94f34f52/Pasted text.txt` (read all sections). Frozen COST-001..007, ADR-0016, M3 evidence and `docs/operations/cost-control.md`.

## Global Constraints

- Work in `/home/rocco/projects/oras-ai`, `m9/production-release`, HEAD `56b8ba9a9ef19bc856b4f4be92ed6a61196e206f`; initially clean. No commits, staging, production access, packaging, deployment, model changes, pricing additions, version changes, or Task 2+ work.
- Preserve 25/day, 150/month, 5/minute, $10 warning, $20 hard stop; answer 4000 bytes, 800 output tokens, 30 seconds.
- Baseline: 204 PHP files linted, 663 PHP tests, 78 frontend assertions, JS/quality exit 0. Existing weather warning line 197 is out of scope and must be reported.
- Remote branch verified matches HEAD; M8 closure is HEAD. No architectural contradiction identified.
- Use GPT-6 Astra / High. No further subagents from implementer. Root handles review and disposable integration.

## Current paid-call inventory (before implementation)

All use configured `ORAS_AI_Config::get_openai_model()`; no live pricing lookup, no retries, no other OpenAI endpoints found in production.

| Family | Initiator | Input | Output/time | Pricing / quota / reservation / reconciliation / failure / hard stop |
|---|---|---|---|---|
| answer | member gateway -> orchestrator; Test Console uses same gateway | 4000-byte question; 16000-byte assembled input; 6000-byte evidence | 800 / 30s | local configured price, member counters, pre-domain answer reservation; reported usage reconciled; local failures release, ambiguous failures settle estimated tokens as actual; hard stop before provider |
| support_summary | escalation proposal -> summary service -> answer provider | 1000-byte question + fixed prompt/schema | 160 / 10s | same controls currently count another question and reserve answer output; reconciles valid normalized response; malformed text can discard known usage; hard stop active; confirm/replay zero calls |
| domain_classifier | domain guard ambiguous request | member path bounded, direct adapter unbounded | absent / 20s | no independent price, quota, reservation, reconciliation; cost lost when answer released; only incidental protection from outer answer admission |
| scanner_classification including mixed extraction | manual Sources scan -> rules -> OpenAI source classifier -> OpenAI::classify_source | 30000 characters source; metadata unbounded | absent / 60s | no price, no quota, no reservation/reconciliation or hard stop; error returns before knowledge replacement |

Scanner extraction is the `stable_fragments`, `excluded_dynamic_claims`, `dynamic_fact_types`, validation portion of the same schema/call. No standalone extraction/normalization dispatch exists. Browser queue stops on processing error; manual initiation, per-source exclusion, required API key; no scanner-wide kill switch or background retry worker found.

## Review Focus

- Direct adapter calls must not bypass pricing/budget even without orchestrator admission.
- Known usage on malformed/incomplete/HTTP-error responses must not be discarded; over-reservation usage cannot be capped away.
- Unknown usage must not be represented as fake measured tokens; idempotent settlement and no release after dispatch.
- Persisted reservation must exist before HTTP, including storage errors/lock contention; stale lock takeover must not create concurrent writers.
- Preserve approved knowledge on denial/incomplete output; practical extraction payload must fit configured cap without silent truncation.

### Task 1: Shared paid-call boundary and regressions (one coordinated implementation)

**Files:** Modify ledger, execution controls/admission as needed, OpenAI answer/domain/scanner adapters, answer orchestrator and support summary service minimally, Cost Admin, plugin bootstrap/tests bootstrap. Create a small shared transport class if needed. Tests in cost/openai/domain/scanner/support suites. Evidence `docs/evidence/m9-site-wide-accounting/verification.md`.

**Interfaces:** Existing `ORAS_AI_Usage_Ledger` remains the only ledger. Keep legacy `reserve` behavior for member admission; add explicit cost-only admission or equivalent and bounded family metadata. Shared transport validates serialized payload, qualified model/rates and bounded output/timeout before HTTP; it either uses a matching existing open reservation or atomically reserves its own. Do not reserve twice for the same answer. Exact interface names may follow repository conventions; record any design change with rationale.

**Chosen caps:** Domain 128 output tokens, 20 seconds, request question bound by configured member input maximum (default 4000 bytes). Only schema `{"domain":"oras|astronomy|crossover|off_topic"}`; longest minimal JSON is 22 bytes, leaving substantial margin; qualify all enums and oversize/incomplete failure with fake responses, explicitly not live-model quality. Scanner 12000 output tokens, 60 seconds, existing 30000-character content limit plus explicit title/URL/type and serialized payload limits. Scanner combines classification and extraction, so separate caps per imaginary operation are inappropriate. Qualify substantial multi-fragment extraction fixtures, metadata and exclusion lists; incomplete or oversized output must fail safely rather than replace knowledge. No tokenizer estimate is proof of live reasoning-model completion; document limitation.

- [x] Read full user spec, exact frozen cost contract and existing production/tests. Confirm inventory; report additional paths before code changes if found.
- [x] Write tests against real adapters/ledger with injected HTTP for every family, before production changes. Test reservation-before-HTTP, caps/timeouts, pricing denied, at/over hard stop zero HTTP, outstanding reservation consumes headroom, known usage, unknown post-dispatch failure, no-spend local failures, malformed output with known usage, overflow actual, no double question count, warning, metadata privacy, source knowledge preservation, admin test console and support proposal/confirm/replay. Add deterministic production dispatch inventory guard (not line-number assertions).
- [x] Run and capture RED output under `/tmp/oras-ai-m9-task1-red.log`. Failures must demonstrate missing behavior, not typos. Notify root before production changes.
- [x] Implement minimum shared boundary, ledger accounting distinction, explicit adapter bounds/caps, safe semantic errors, metadata-only source breakdown in existing Usage & Cost. Keep frozen prices/model untouched. Record unknown spend as conservative amount with null actual token fields, not fabricated usage. Reconcile known usage even if downstream parsing fails. Preserve known excess spend and deny future admissions.
- [x] Run focused tests until green, preserving tests' intended assertions. Legacy tests may require fixtures to configure pricing; explain semantic updates (especially unknown status and auxiliary question count).
- [x] Run full `npm test`, capture result; root runs final requested full verification after review. Write report with changed files, test coverage mapping, exact counts, caps/limits, RED/GREEN, limitations. No commit.

## Execution record

Pre-flight: one coherent task owns shared ledger/provider interfaces; no parallel production edits. Root owns only plan/evidence and disposable check until implementation finishes. User's no-commit/no-intermediate-review instruction controls execution. Task remains open until independent review and final fresh checks.

Progress: RED observed before production changes: 30 new failures with baseline 663 passing, `/tmp/oras-ai-m9-task1-red.log`. Four production dispatch families confirmed. Implementation underway.

Completion: 705 PHP tests, 78 frontend assertions, 207 PHP files linted, JS/quality/diff checks pass. Independent review found and reverified fixes for native option-lock races and settlement-contention faults; no Important/Critical findings remain. Native disposable hard-stop/cache/race probe passed, options restored, temporary files removed, and stack stopped. Two pre-existing weather warnings disclosed. No commit or prohibited scope work; ready for owner review. See `docs/evidence/m9-site-wide-accounting/verification.md`.
