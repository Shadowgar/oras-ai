# M8 member-aware actions final qualification

**M8 STATUS: READY TO CLOSE** — 2026-10-01, pending owner review of these
uncommitted closure documents. Every frozen M8 development blocker passes;
the roadmap records development qualification complete. Production deployment
verification remains separate.

**Implementation HEAD before M8 closure documentation:**
`816da5adf311049f6a95a288a484d0e3337a417f`

## Verified baseline and scope

The checkout is `/home/rocco/projects/oras-ai`, branch
`m8/member-aware-actions`. `git fetch origin` succeeded; the implementation HEAD
matches `origin/m8/member-aware-actions`, ahead/behind **0/0**. The worktree was
clean before this task. Recent committed implementation:

| Commit | Implementation |
|---|---|
| `816da5adf311049f6a95a288a484d0e3337a417f` | Pass/event offerings and all four offering-grounding/partial-failure corrections. |
| `14a0cd9dc921d05ab742aa155e9028a0c63b72f6` | Authenticated self-membership answers. |
| `14fceaf76c0e6fa8fe76f304305bb1faae10f32d` | Frozen M8 action boundaries and acceptance tests. |
| `039d6ae8138f9641b37b8715c7efac4ddd939b03` | M7 Fluent Support qualification and SUP-004 summary correction. |

This task changes closure documentation only. Plugin header, runtime constant,
and package version remain **0.2.1**. There is no new feature, owned-pass lookup,
membership mutation, provider, payment/order/cart behavior, registration
submission, release/package artifact, or M9 implementation. ASTRO-007 remains
deferred. No commit or future closure commit hash is claimed.

## Fresh full quality

The following bundle passed at the clean implementation baseline and again
after the closure documentation changes:

```text
npm run lint:php       exit 0; 204 PHP files passed lint
npm run lint:js        exit 0; node --check assets/scanner.js and assets/chat.js
npm test              exit 0; 663 PHP tests, 78 frontend chat assertions
npm run quality       exit 0; PHP/JS lint and the complete test suite
git diff --check      exit 0
```

`tools/run-tests.php` discovers and executes every registered `*Test.php` test;
no milestone subset was substituted for the full M1–M8 regression. Test counts
remain unchanged. Frontend qualification uses the repository's simulated DOM;
this task makes no new browser, assistive-technology, or production UI claim.

## Frozen requirements and acceptance results

| Requirement | Result and reproducible evidence |
|---|---|
| ACT-001 | **PASS.** Server-derived canonical product/event URLs pass the unchanged HTTPS/host allowlist before rendering. Unsafe/missing links cannot be replaced by member/model URLs. |
| ACT-002 | **PASS.** WooCommerce remains the member-operated purchasing path; ORAS AI supplies qualified facts and descriptive handoff links. |
| ACT-003 | **PASS.** No autonomous charge/order completion or commerce mutation is exposed. Hostile purchase/payment/order/checkout success prose is removed from qualified offering answers. |
| ACT-004 | **PASS.** M8 read/link actions have no business side effect. The inherited M7 ticket side effect still requires explicit server-validated confirmation. |
| AT-ACTION-001 | **PASS.** `ActionBoundaryTest.php` tests canonical URLs, injected destinations/IDs, unsafe and missing links; `OfferingCorrectionTest.php` tests unsafe optional event links without fact loss. |
| AT-ACTION-002 | **PASS.** Current Woo stock/purchasability and stated price control pass answers. Schedule alone cannot establish registration. `PassOfferingQualificationTest.php` and `EventOfferingQualificationTest.php` cover business states and failures. |
| AT-ACTION-003 | **PASS.** Hostile prices/totals and completed purchase/payment claims are rejected; runtime source and capability/transport tests exclude autonomous commerce. |
| AT-ACTION-004 | **PASS.** Authenticated self-membership, current levels, unauthorized identities, and private/raw field exclusion pass in `PMProMembershipAnswerTest.php`, `PMProContextConnectorTest.php`, and `ActionBoundaryTest.php`. |
| AT-ACTION-005 | **PASS.** `EscalationConfirmationTest.php`: proposal/cancellation zero tickets, explicit confirm one attempt, replay no duplicate, uncertain result no automatic retry. |
| AUTH-001–005 | **PASS in automated qualification.** `RequestGatewayTest.php`, access/admin security, and conversation transport tests enforce server identity, eligibility, visibility, capabilities, nonce, and anonymous denial. Production membership policy remains deployment configuration. |
| LIVE-001 | **PASS.** TEC owns event identity/schedule; exact resolved event IDs reach the offering read. |
| LIVE-002 | **PASS.** Woo owns distinct Annual/Daily identity, current price/currency, stock, and purchasability. |
| LIVE-003 | **PASS.** PMPro/WordPress supplies only authorized self-membership state. |
| LIVE-004 | **PASS.** Operational failures remain bounded, matching stale facts suppressed, healthy siblings retained. |
| LIVE-005 | **PASS.** Only normalized necessary facts enter evidence/model context; raw provider objects/rows are excluded. |
| COST-001–006 | **PASS.** Complete execution-control/ledger tests cover quotas, burst, input/output bounds, admin visibility, pricing admission, and hard stop. |
| COST-007 / performance targets | **PASS for bounded fixture qualification.** No composition-driven repeated model/provider work; local timings below are not production latency measurements. |
| UX-005 / UX-006 | **PASS.** Descriptive canonical source links and bounded errors; no raw exception, key, prompt, or stack exposed by M8 outcomes. |
| NFR-SEC-001–007 | **PASS in automated qualification.** Secrets stay server-side; capability/CSRF/visibility boundaries, injection/URL constraints, minimized context, and safe audit/logging tests remain green. |
| NFR-PRIV-004 | **PASS.** The gateway rejects validated payment-card patterns before provider dispatch/storage without echoing or logging them. |
| NFR-A11Y-001–004 | **PASS in automated frontend qualification.** Native named links/buttons, focus/keyboard behavior, accessible status/errors, textual unknown states, mobile/shared panel, and M7 confirmation controls remain covered. |
| NFR-REL-001 / NFR-REL-005 | **PASS.** Optional failures remain scoped and execution bounded; no provider retry loop was added. |
| NFR-OBS-002 | **PASS.** Safe bounded connector health/counts; business inactive/full/closed states are not outages. |

The frozen [requirements](../../requirements/functional-requirements.md),
[non-functional requirements](../../requirements/non-functional-requirements.md),
[acceptance catalog](../../quality/acceptance-test-catalog.md), and
[traceability](../../requirements/traceability-matrix.md) already describe these
contracts accurately and were not rewritten.

## Membership, offering, and hostile-model qualification

`What is my membership status?`, `What membership level do I have?`, and
`Am I an active ORAS member?` were freshly rerun. Current authenticated WordPress
identity controls lookup. Browser IDs, typed email, model-selected IDs, foreign
PMPro rows, and malformed identities cannot redirect it. Inactive and one-level
states are deterministic; multiple active levels permit a status-only answer
but an ambiguous requested level remains unknown. Administrator AI access does
not imply membership, and the PMPro virtual-access filter is scoped/restored.
Current PMPro facts outrank matching stale content; hostile active/inactive or
invented-level prose cannot contradict the normalized answer.

Annual, Daily, generic, price/cost, availability, and explicit where-to-buy/get
Observer Pass intents pass. Routing requires explicit Observer Pass context;
standalone `Where can I get one?` remains a bounded refusal with zero lookup.
Fixture current prices are **45.00 USD Annual** (active sale, regular **50.00
USD**) and **12.50 USD Daily**. They are test values, not production prices.
Qualified backorders remain member-operated handoffs; out-of-stock or
non-purchasable products cannot receive a purchase recommendation.

The exact hostile `How much is an Observer Pass?` probe returned both provider
prices. Permanent tests inject `$1 / 1.00 USD`, invented tax, `paid`, `purchased`,
`order completed`, `checkout completed`, and `https://evil.example/pay`; none
survive. Price-only requests disclose unavailable purchasability when those
fields were not requested/admitted. No substitute canonical URL is invented.
Hostile currency/stock/purchasability and event open/full/closed/unknown claims
are bounded by the same deterministic admitted-fact fragments, independent of
model prose. Model output has no dispatch mechanism for user/product/event/
ticket/RSVP IDs, provider methods, arbitrary actions, or URLs.

An additional fresh combined hostile probe injected contradictory membership,
`1.00 EUR`, stock/purchasability, IDs/methods, payment/completion, and arbitrary
URL prose for each event state (`open`, `full`, `closed`, `unknown`). Every run
retained active/Family membership, the correct USD prices and purchasability,
and its exact event state. Each run used one PMPro, Woo, TEC, and offering-loader
call and one answer-model fixture call; all hostile substitutions were removed.

TEC owns schedules, ORAS Tickets owns registration state, and paid offerings
use native Woo stock/purchasability. Open/full/closed/unknown, no offering,
provider failure, mixed non-open siblings, and unrelated event preservation
pass. Schedule alone does not establish registration. Unsafe optional event
links remove only the URL: title/start/end/timezone/venue and qualified
registration state survive, with no empty Sources entry, invented replacement,
or absent-link instruction to register.

## Combined answers and partial failure

`OfferingCorrectionTest.php` freshly passes all four combinations: membership
plus pass, membership plus event, pass plus event, and all three. Independent fragments
and safe links coexist. The all-domain request is:

> What is my membership status, what Observer Passes can I buy, and can I register for AstroBlast?

| Injected failure | Qualified result retained |
|---|---|
| PMPro unavailable | Membership unavailable; Annual/Daily offerings and event schedule/registration survive. |
| Woo unavailable | Pass unavailable; membership and event schedule/registration survive. |
| Event offering unavailable | Membership/pass survive; TEC schedule survives; registration unavailable. |

Each applicable injected provider loader runs once for these exact-subject
combinations; the answer provider runs once. Composition adds no retrieval,
retry, or second model call. Native candidate/variation reads remain bounded by
the existing connector limits: ten TEC candidates and twenty Woo root products.

Annual failure preserves Daily, and Daily failure preserves Annual. Unknown or
malformed unrelated candidates are not admitted or arbitrarily classified.
Same-type duplicates produce bounded ambiguity without choosing a winner; a
known malformed duplicate cannot silently disappear and allow an arbitrary
winner. Native malformed objects, missing methods/variations, and thrown
ID/children/price reads are also covered.

A malformed price suppresses only the matching stale price while independently
qualified availability, safe link, and sibling price survive. Failed option/
field identities cannot suppress unrelated current or stable facts. Existing
M5 fact precedence and the 16,000-character model-context bound are unchanged.
All six former qualification probes passed again, with zero extra failures.

## No autonomous commerce, privacy, security, and inherited M7

Source inspection and the fresh scan of **102 runtime PHP files** found zero
forbidden commerce/membership API matches. Combined with read-only connector,
empty model-capability registry, and fixed transport-operation tests, M8 exposes
no add-to-cart/cart mutation, checkout/order/payment/refund creation, stock or
pass ownership mutation, RSVP submission, attendee registration, capacity
reservation/decrement, or membership cancellation/renewal/level-change path.
The only inherited business side effect is confirmed M7 support-ticket creation.

M7 requalification proves proposal zero tickets, cancellation zero tickets,
confirmation one provider attempt, replay no duplicate, and uncertain creation
no automatic retry. Existing crash-window uncertainty remains bounded as
documented in the [M7 evidence](../m7-fluent-support-escalation/verification.md).
No live Fluent Support ticket was created in this task.

The unchanged URL policy requires explicit allowed HTTPS hosts, rejects private/
local destinations, userinfo and unexpected ports, and never fetches a supplied
URL. HTTP, member/model links, arbitrary hosts, javascript, and malformed URLs
cannot become M8 handoffs. Native links have descriptive names and keyboard
access. Simulated frontend tests also cover focus, shared/mobile panel, textual
unknown availability, confirmation preview/actions, cancellation, and terminal
uncertain states.

Model context excludes billing/address/payment/history fields, owned/manual
pass records, another member's membership, attendee/email/purchaser/private RSVP
answers, and raw Woo/PMPro/ORAS Tickets objects. Provider-health storage retains
only connector state, bounded count/reason, and timestamp; it stores no member,
order, attendee, request, or provider payload. PMPro/Woo failures and
`event_offering_unavailable` are bounded operational outcomes; inactive
membership and valid full/closed states do not increment outage counters.

## Fresh disposable installed-site qualification

Only the **tests** database in `/home/rocco/projects/oras-wp-env` was used.
Fresh installed versions were **WooCommerce 11.1.0**, **TEC 6.17.3.1**,
**ORAS Tickets 0.4.60**, and **PMPro 3.8.4**. No production data was imported or
accessed. Current implementation connector files were copied to container
`/tmp` without installing/activating ORAS AI. The probe required WP-CLI and the
test site's `:8889` home URL; external HTTP and mail were blocked for the probe.

The probe created one synthetic published TEC event through `tribe_events()`
ORM, one stocked Woo ticket product, and synthetic Annual/Daily products.
`_oras_tickets_v1` plus `_oras_tickets_woo_map_v1` associated only the synthetic
event/product; `_oras_rsvp_v1` supplied the synthetic RSVP window. TEC-native
decorated dates/link supplied the event-loader record; native ORAS Tickets/Woo
reads supplied offering state. Native Woo discovery read the synthetic passes.

| Disposable read | Fresh observed result |
|---|---|
| Stocked paid offering in sale window | `open`; three admitted schedule/registration facts. |
| Paid stock changed to zero | `full`; three admitted facts. |
| Paid sale window ended | `closed`; three admitted facts. |
| Paid product mapping absent | `unknown`; schedule and bounded state retained. |
| No offering association | `event_offering_unavailable` scoped to registration; two schedule facts retained. |
| RSVP window open / closed | Native `open` / `closed`; three admitted facts each. |
| RSVP pure capacity decision: 3 places, 3 admitted | `refuse`; full normalization separately covered by automated fixtures. No registration was submitted. |
| Local HTTP event URL | Rejected; admitted event URLs empty, all valid facts retained. |
| Native Annual/Daily Woo reads | `45.00 USD` / `12.50 USD`, `instock\|yes`, purchasable `yes`. |
| Native local HTTP Woo handoff | `unknown / unsafe_canonical_url`, zero admitted facts, consistent with the frozen required-URL product contract. |
| PMPro contract fakes in WordPress | Inactive/none, active/one level, and bounded multi-level ambiguity. No actual membership was created or changed. |

All fixture event/product/pass records were deleted and their absence checked.
Temporary container code was removed. The previously stopped wp-env stack was
stopped again after qualification; unrelated running services were preserved.
M7 confirmation is freshly fixture-qualified here; the earlier disposable
Fluent Support qualification is historical evidence, not a fresh installed-site
run in this task.

## Production deployment verification still required

These values were **not queried or invented**. They are deployment items rather
than unmet frozen M8 implementation requirements:

| Production item | Verification state |
|---|---|
| Real Annual/Daily product names, IDs, types, variations | Unverified. |
| Actual production current/sale prices, currency, stock, purchasability/backorders | Unverified. |
| Canonical production HTTPS product URLs and allowlist | Unverified against the deployed site. |
| Actual WooCommerce, TEC, and ORAS Tickets installed versions | Unverified; disposable versions above do not establish production versions. |
| Real event-to-offering associations | Unverified. |
| Real paid/RSVP capacities and sale/registration windows | Unverified. |
| Canonical production HTTPS event URLs and allowlist | Unverified against the deployed site. |
| Actual production PMPro version and membership-level names/policy | Unverified. |

## Local performance/cost and known warnings

Fresh local PHP fixture timings, **200 samples per path**, exclude network,
credentialed model execution, production database cost, and browser latency.
Answer timings include fixture reset/setup and the stub answer provider:

| Path | Median ms | p95 ms |
|---|---:|---:|
| PMPro normalization | 0.0060 | 0.0073 |
| Annual/Daily normalization | 0.0279 | 0.0355 |
| TEC/offering normalization | 0.0184 | 0.0214 |
| Membership answer | 0.0765 | 0.1085 |
| Combined answer | 0.1956 | 0.2733 |

M3 quota, rate, pricing admission, usage ledger, URL policy, source precedence,
request authorization, and M7 confirmation implementations have no diff from
the committed M7 baseline. Deterministic composition introduces no second model
call or side-effect cost path. No credentialed AI calls or production charges
were made.

Both inherited weather warnings reproduced: optional regex capture **6** at
`class-oras-ai-current-weather-service.php:197` and capture **3** at line **207**
are accessed when absent. They occur in M6 explicit observing-time parsing;
the relevant tests still pass, and M8 membership/pass/event paths do not use
this parser. They remain unrelated nonblocking debt and were not changed.

## Evidence records and final disposition

The committed tests and native fixture in `tests/fixtures/woo-native-offering-probe.php`
provide permanent reproducible acceptance coverage. Fresh session logs are
supplementary local records, not permanent test dependencies:

- `/tmp/oras-ai-m8-task5-baseline-{lint-php,lint-js,test,quality}.log`
- `/tmp/oras-ai-m8-task5-probes.log`: six former failures pass, zero extra failures.
- `/tmp/oras-ai-m8-task5-hostile.log`: four combined hostile state/dispatch probes pass.
- `/tmp/oras-ai-m8-task5-disposable.log`: installed-site outcomes and clean fixture removal.
- `/tmp/oras-ai-m8-task5-timing.log`: local fixture timing samples.
- `/tmp/oras-ai-m8-task5-final-{lint-php,lint-js,test,quality}.log`: post-documentation bundle.

No frozen M8 blocker remains. Automated acceptance, hostile-model grounding,
combined-domain preservation, partial-failure isolation, inherited M7
confirmation, security/privacy/UX, and applicable regressions pass. The two
closure documents remain uncommitted for owner review. No production readiness,
release, owner approval, or M9 completion is asserted.
