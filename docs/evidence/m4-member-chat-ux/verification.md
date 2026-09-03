# M4 Member Chat UX Closure Verification

- **Milestone:** M4 — Member Chat UX
- **Status:** COMPLETE
- **Verification date:** 2026-09-03
- **Branch:** `m4/member-chat-ux`
- **Implementation HEAD:** `89c7ee9703604aac44784638d92193a93d9d8288`
- **Plugin version:** `0.2.1`

The exact implementation HEAD above is the reproducible committed M4 implementation identifier. This closure documentation and the additional browser-like qualification assertions remain uncommitted pending owner review.

## Implementation commits

- Task 1 — conversation privacy and retention boundary: `37df0cd87a1d9e54aca19fd730b1030c3c3365e9`
- Owner-approved site-wide Support UX clarification: `7dc43fdf78ae30b74f2d73253652db7021b0b83a`
- Task 2 — authenticated conversation transport: `71ed6239ca15a8003249cba6b3d59cc9d13bf167`
- Task 3 — accessible member Support chat: `bdef013e064bdf2930e67e2f0ca31f7b47bf3dd5`
- Task 4 — protected administrator test console: `89c7ee9703604aac44784638d92193a93d9d8288`

## Quality verification

Commands:

```bash
npm run lint:php
npm run lint:js
npm test
npm run quality
git diff --check
```

Result: **PASS**

- PHP syntax lint: **PASS — 79 PHP files passed lint**.
- JavaScript validation: **PASS — `node --check assets/scanner.js` completed successfully**.
- Automated PHP tests: **PASS — 255 tests passed, 0 failed**.
- Frontend chat harness: **PASS — 26 assertions passed**.
- Aggregate quality command: **PASS — exit code 0**.
- Patch whitespace validation: **PASS — no output**.

## Frozen M4 blocker verification

1. [x] **Dedicated member chat — PASS.** The plugin renders the shared member assistant through `[oras_ai_chat]` and the site-wide Support panel.
2. [x] **Dedicated shortcode page — PASS.** `[oras_ai_chat]` renders the same underlying page-mode component used by the floating experience.
3. [x] **Site-wide Support launcher — PASS.** Eligible authenticated members receive the plugin-owned **Support** launcher; activating it opens the overlay without navigation. Anonymous and ineligible users receive no launcher.
4. [x] **Shared component/backend — PASS.** The panel, shortcode page, and admin console reuse one PHP component, JavaScript controller, authenticated conversation transport, request gateway, response renderer, and M3 orchestration path. No duplicate answer backend or alternate provider endpoint exists.
5. [x] **Member-only availability — PASS.** Identity and eligibility are server-derived. Administrators follow the existing allowance; the global AI kill switch prevents member and administrator use.
6. [x] **Restore and New Chat — PASS.** The current/latest owned conversation restores on initialization. New Chat creates a new current conversation while preserving the previous retained conversation.
7. [x] **Progress/error UX — PASS.** Success, refusal, no-evidence, sensitive-input, quota/rate/cost denial, unavailable/kill-switch, and provider-failure paths have bounded safe presentation without internal detail.
8. [x] **Source/action rendering — PASS for the M4 slice.** Trusted server-provided source titles and canonical URLs render beneath the corresponding assistant answer. M4 does not implement member actions; those remain M8.
9. [x] **Accessibility — PASS.** Native labeled controls, dialog semantics, keyboard activation, Escape close, focus transfer/return, polite live regions, textual states, descriptive links, and the small-screen panel contract are covered by markup and browser-like tests.
10. [x] **Privacy/retention — PASS.** Conversation text expires after 30 days, empty expired shells are removed, external-AI and retention disclosures are visible, payment-card-like input is blocked before storage/provider work, and usage metadata remains separate.
11. [x] **Admin test console — PASS.** The console requires `manage_options`, uses the administrator's own conversations and normal transport/orchestration/security path, retains the global kill switch, and loads shared chat assets only on its own page.

## Member surfaces and eligibility

- The site-wide **Support** launcher and `[oras_ai_chat]` page use the same rendered component with panel/page presentation modes.
- Eligible authenticated members receive the launcher and shortcode chat. Anonymous and ineligible users do not receive the launcher or a usable shortcode chat.
- Administrators may use the established administrative allowance without an active PMPro membership. This does not create a new exception, identity override, or visibility override.
- Disabling member AI hides the frontend launcher and causes the normal authenticated transport to deny administrators as well as members.
- The fixed identity remains **ORAS AI / ORAS AI Assistant**. Rotating or session-specific space names are not authorized by the frozen documentation and remain a possible future owner-approved UX enhancement, not an M4 blocker.

## Conversations and privacy

- Conversation ownership comes only from the authenticated WordPress user. Browser-supplied user/owner fields are ignored, cross-user reads and writes fail, and anonymous conversation creation fails.
- Current restoration, New Chat, retained prior conversations, ordered safe transcripts, and 30-day expiry are covered by automated tests.
- No conversation-history/sidebar browser or administrator surveillance view exists.
- Stored transcript history is not sent back to the answer model on later turns; each submitted question follows the normal authorization/domain/retrieval/grounding path independently.
- Conversation records store only the minimum owner, role, plain-text message, source-reference, and retention timestamps required by the implementation. API keys, system/developer prompts, retrieved evidence bodies, raw provider payloads, PMPro internals, hidden authorization state, cost reservations, stack traces, and database details are not stored or rendered.
- Payment-card-like input is rejected before transcript storage and provider execution without echoing or logging the card number.
- M3 usage/cost metadata retains its separate 12-month policy and contains no full conversation text.

## Sources and response states

- Source references are constructed server-side from admitted evidence and normalized by the conversation boundary. Only the source title and canonical HTTP(S) URL become links.
- Model-written URLs are plain answer text and cannot be promoted to trusted citations. Invalid URL schemes are rejected by the client renderer.
- Sources render directly beneath their assistant message. Answers without trusted sources, including general astronomy answers, omit the Sources section.
- The shared renderer distinguishes normal answer completion, refusal, no evidence, sensitive input, quota/rate/site-cost denial, unavailable/kill-switch behavior, and provider failure using safe localized text. It does not expose raw provider errors or hidden details.

## Accessibility and responsive behavior

- The launcher, New Chat, close, input, Send, and source links use native keyboard-operable elements with descriptive visible labels.
- The floating panel has dialog labeling and modal semantics. Opening moves focus to the input; Escape closes it and returns focus to the launcher.
- Transcript and status regions use `role="log"`/`role="status"` and polite live-region behavior. Pending/error/notice text accompanies visual state, so color is not the sole indicator.
- The responsive stylesheet changes the desktop overlay to a near/full-screen panel at the documented 600-pixel breakpoint while retaining the same component and controls.

## Admin test console

- **ORAS AI — Test Console** is registered under the existing plugin menu with `manage_options`; its render and asset paths repeat the capability check.
- A non-administrator cannot render the console or receive its nonce/configuration. Shared chat assets are not loaded on unrelated wp-admin pages.
- The console runs as the authenticated administrator, uses that user's own current conversation, and cannot inspect another user's transcript.
- It uses the configured model through the existing conversation transport, request gateway, domain guard, retrieval, grounding, quota/cost controls, provider adapter, and source handling.
- It has no member selector, impersonation, PMPro override, model selector, prompt viewer, raw request/provider debugger, or alternate endpoint.

## Milestone staging clarifications

- `UX-004` escalation preview is to be implemented and qualified with the Fluent Support workflow in M7. No ticket escalation is implemented in M4.
- `NFR-PRIV-003` ticket-linked context minimization remains required for M7, when ticket linkage exists.
- The M4 source/action blocker qualifies the currently available trusted source links. Actual member actions and their confirmation boundaries remain M8 under `ACT-*`.

These staging statements preserve the substantive requirements; they do not weaken or remove them.

## Local qualification limits

Qualification used the repository's WordPress PHP harness and Node VM browser-like frontend harness. The Node harness executes the shared controller and verifies panel open/close, Escape/focus return, restoration, New Chat, send/busy behavior, safe sources/states, and responsive CSS markers.

This repository path has no `wp-config.php`, and WP-CLI reports that it is not a WordPress installation. Therefore no live rendered WordPress browser, staging, real PMPro membership, production model call, or production ORAS dataset was available or claimed. No ORAS.org production data was imported or cloned.

## Deferred M5+ scope

M4 closure does not implement live Events Calendar, WooCommerce, or member-context PMPro connectors (M5); current astronomy/weather providers or recommendations (M6); Fluent Support escalation/tickets (M7); or member actions/commerce confirmation workflows (M8). It also does not add rotating space-themed identities.

The plugin remains version `0.2.1`. No ZIP, checksum, release artifact, release tag, production-data import, or version bump was created.
