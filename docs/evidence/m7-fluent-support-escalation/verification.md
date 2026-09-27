# M7 Fluent Support escalation and feedback qualification

**Decision:** READY TO CLOSE for the frozen M7 development milestone, pending owner review. This is a development qualification, not production ticket, notification, or credentialed OpenAI verification.

**Implementation HEAD before M7 closure documentation:** `45e1aa253be4ab86dacf7a03e183e374bddc9d31` on `m7/fluent-support-escalation`, matching `origin/m7/fluent-support-escalation`. Task 5 and the SUP-004 correction remain uncommitted. Plugin version remains `0.2.1`; no M8 work or release artifact was made.

## Frozen contract and correction

`SUP-004 [RB]` requires **both** the original member question and a distinct concise AI summary in a confirmed ticket. `AT-SUPPORT-003` also requires identity and category. The old production call passed no `model_candidate` to the proposal service, so its summary fallback was `Member asks for help: <original question>`. That was bounded but repeated the question. The no-evidence ORAS path often returns before an answer-model call, so a same-call structured summary would not cover the primary escalation case.

The correction makes **one dedicated support-summary call** after the server confirms an appropriate support intent and a valid provider route. It uses the existing configured OpenAI answer-provider class, existing `ORAS_AI_Execution_Controls`, and existing usage ledger. The request contains only the current plain, bounded member question; no history, identity, evidence, mailbox/tag IDs, tools, or web access. It uses the configured approved model, at most 160 output tokens, and at most 10 seconds. The strict response schema permits one `summary` string and no other fields. The server rejects empty, malformed, oversized, HTML or encoded HTML, obvious question duplicates/prefixes, new numeric claims, and provider-ID fields. Topic and route remain server-owned. The prompt directs the model to describe the member's issue without answering, inventing policy/account/payment facts, claiming staff action, or promising resolution. A model output is a proposed summary shown to the member before confirmation, not authoritative ORAS knowledge.

The M3 admission path reserves cost **before** dispatch; daily/monthly/burst and site hard-stop policy apply. A successful response reconciles actual usage, a pre-dispatch failure releases the reservation, and an ambiguous post-dispatch failure conservatively settles the reserved maximum. Configured warning/hard-stop values remain $10/$20. A denied, failed, timed-out, or invalid summary yields `unavailable`: normal chat remains usable and the manual ORAS contact route is shown, with no ticket proposal or Create action. There is no retry loop. No summary call occurs on confirm, cancel, status, refresh, replay, or duplicate confirmation.

## M7 requirements

| Requirement | Result and evidence |
|---|---|
| SUP-001 | **PASS.** Fluent Support remains the ticket system of record; ORAS AI does not maintain a parallel ticket lifecycle. |
| SUP-002 | **PASS.** No-evidence ORAS/support path offers escalation; answerable support and general astronomy do not unnecessarily call the summary model or create tickets. |
| SUP-003 | **PASS.** Proposal/preview has zero Fluent Support writes; only explicit confirmation creates a ticket. |
| SUP-004 | **PASS.** The stored and confirmed payload contains the bounded original question and a distinct model-generated issue summary. `SupportSummaryTest.php` rejects the old repeated-question pattern. |
| SUP-005 | **PASS.** Only the relevant current question and summary are submitted; no full transcript. |
| SUP-006 | **PASS.** WordPress configuration maps server-owned topics to validated mailbox/tags; no person is hard-coded. Disposable provider tag attachment passed. |
| SUP-007 | **PASS.** Bugs, suggestions, ideas, and complaints use the same confirmed support bridge. |
| SUP-008 | **PASS.** Explicit admin action on a closed/resolved ticket creates a Needs Review knowledge artifact, never auto-approved. |
| SUP-009 | **PASS for M7 / RD production policy.** Anonymous AI-driven ticket creation remains disabled. |
| SUP-010 | **PASS.** Invalid/missing topic mapping uses General ORAS Support; invalid General/primary route fails closed. |
| UX-004 / AT-UX-002 | **PASS.** Category, subject, AI summary, original question, destination, and retention disclosure appear before confirmation. |
| ADM-005 M7 | **PASS.** Routing settings and support knowledge candidate action use protected admin controls. |
| NFR-PRIV-003 | **PASS.** Ticket/candidate linkage contains only necessary context and IDs. |
| AT-SUPPORT-001–006 | **PASS in automated qualification.** `AT-SUPPORT-003` asserts exact original question plus distinct AI summary, linked customer, configured mailbox/topic tag, and one confirmed ticket path. `AT-SUPPORT-006` also passed against disposable Fluent Support. |

## Confirmation, failure, privacy, and provider evidence

The accepted summary is persisted once in the owner-bound pending record. Status/load, refresh, confirmation, and replay return or submit the exact stored text without calling the model again. Confirmation preserves the server-selected customer, mailbox, tags, and ticket body; it does not accept browser replacement fields. Cancellation and expiry have no ticket side effect. Duplicate confirmation replays the stored result. Ambiguous/null provider create results stay uncertain and are never automatically retried. Cross-user/conversation token use, missing nonce, ineligible member, anonymous user, disabled AI, stale route, customer conflict, missing email, invalid mailbox/tag, and provider outage have bounded outcomes. Semantic audits do not include ticket body, private identity, summary, or raw exceptions.

The disposable `/home/rocco/projects/oras-wp-env` site reported active Fluent Support core **2.4.0** and Pro **2.4.0**. With mail intercepted, a new confirmed test ticket resolved the linked customer and explicit mailbox, and an existing test tag attached. The open ticket was ineligible for knowledge; after the provider's close operation, an admin action created one `review` artifact. Repeating the action returned that same artifact. The artifact had an empty answer, admin visibility, safe provider/ticket provenance, and no active retrieval eligibility. The disposable ticket/candidate were deleted, and the temporary ORAS AI plugin copy was deactivated and removed. These are local provider checks, not production notification or ticket verification.

Task 5 reuses the existing `oras_ai_knowledge` lifecycle. The candidate stores no subject, question, answer, email, name, customer/user ID, reply, internal note, attachment, full transcript, raw metadata, or private ticket URL. A reviewer must inspect the provider ticket and write/approve safe knowledge separately. The per-ticket atomic option claim prevents double-click duplicates; an interrupted claim stays pending for manual reconciliation. Chat text and pending escalation records retain their existing bounded 30-day lifecycle; confirmed support tickets follow Fluent Support retention separately. The member sees the handoff and retention disclosure before confirmation. Frontend assertions cover keyboard/focus/status/mobile behavior, all support states, and manual contact on summary failure.

**Known provider limit:** Fluent Support may insert a ticket and then return an ambiguous/null result. No supported reverse correlation lookup covers a process crash after insert and before ORAS AI persists the ticket ID. Strict exactly-once recovery cannot be guaranteed in that window. The implemented safety behavior is `creating → uncertain → no automatic retry`; this prevents a duplicate retry and avoids a false success/failure claim. This bounded limit does not leave a frozen M7 blocker.

## Verification and deployment boundary

Tests for the SUP-004 correction were added first and observed RED for the missing metered summary service and provider method, then GREEN. The corrected suite has **570 PHP tests**, **68 frontend chat assertions**, and **198 PHP files** passing lint. JavaScript syntax validation, `npm run quality`, and `git diff --check` pass. The OpenAI summary HTTP contract is tested with mock responses, including strict schema, configured model, no tools, bounds, malformed responses, and usage normalization. A live credentialed OpenAI summary was **not** run; model output quality under real credentials remains a deployment verification item.

Production configuration still needs an approved OpenAI key/model and local price rates, the real primary ORAS Support mailbox ID and topic/tag mappings, optional team/agent destinations, membership/notification settings, Fluent Support mail configuration, retention settings, and production-safe integration/rollback verification. None of those deployment values is invented here. No production WordPress or external mail was touched.
