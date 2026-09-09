# M5 Live ORAS Integrations Closure Verification

- **Milestone:** M5 — Live ORAS Integrations
- **Status:** COMPLETE
- **Verification date:** 2026-09-08
- **Branch:** `m5/live-oras-integrations`
- **Implementation HEAD:** `09328736f71fe1bb667bc5eb408f83ffd70f7213`
- **Plugin version:** `0.2.1`

The exact implementation HEAD above is the reproducible committed M5 implementation state. This closure documentation remains uncommitted pending owner review.

## Implementation commits

- Task 1 — live Events Calendar grounding: `9f3e0c2e5f5dec9aeb8303d70f0c56fc5f15634f`
- Task 2 — read-only WooCommerce live grounding: `39b78267bef66784286449d2096f97416f6cdc33`
- Task 3 — minimized PMPro member context: `7872d6384dae46fb3d5c194bde832666b64921a1`
- Task 4 — connector qualification visibility: `09328736f71fe1bb667bc5eb408f83ffd70f7213`

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

- PHP syntax lint: **PASS — 97 PHP files passed lint**.
- JavaScript validation: **PASS — `node --check assets/scanner.js` completed successfully**.
- Automated PHP tests: **PASS — 307 tests passed, 0 failed**.
- Frontend chat harness: **PASS — 26 assertions passed**.
- Aggregate quality command: **PASS — exit code 0**.
- Patch whitespace validation: **PASS — no output**.

## Frozen M5 blocker verification

1. [x] **Events Calendar connector — PASS.** Current/upcoming AstroBlast and Public Night event fields are loaded from The Events Calendar through a bounded adapter and normalized as field-level live facts.
2. [x] **WooCommerce connector — PASS.** Annual and Daily Observer Pass price, availability, and purchasability are separately normalized from WooCommerce state.
3. [x] **PMPro connector — PASS.** Authorized self-membership status and, when unambiguous, the current sanitized level name are obtained through a distinct least-field PMPro context adapter.
4. [x] **Live/static conflict resolution — PASS.** `live_oras_state` wins only for matching normalized fact identities; unrelated synchronized evidence remains eligible.
5. [x] **Canonical action/source links — PASS.** Non-empty Events and WooCommerce URLs originate in server-side connector records and must pass the existing URL policy. PMPro facts may be URL-less and do not create empty Sources items.
6. [x] **No autonomous purchase behavior — PASS.** The live layer reads WooCommerce product state and returns a validated canonical destination; it has no cart, checkout, order, charge, payment, or other commerce mutation path.
7. [x] **Failure isolation and bounded uncertainty — PASS.** Connector exceptions, unavailable dependencies, malformed results, invalid vendor state, and unsafe URLs become bounded reasons. A failed required fact suppresses static substitution only for that exact fact identity.
8. [x] **Least-field enforcement — PASS.** Only normalized answer-relevant fact fields can enter evidence/model context; raw vendor objects and unrelated private fields remain outside the contract.
9. [x] **Connector observability — PASS.** Three fixed connector aggregates count allowlisted operational failures without retaining prompts, identities, facts, vendor payloads, raw exceptions, or event histories.
10. [x] **Connector Health administration — PASS.** ORAS AI exposes a read-only `manage_options` health page with dependency availability, operational state, cumulative failures, and last safe failure reason/time.

## Events Calendar qualification

- Routing is deterministic and limited to supported current/upcoming AstroBlast and Public Night questions; unrelated, ambiguous, and historical requests do not invoke the adapter.
- Only published applicable events qualify. A running event remains current until its authoritative end; ended events do not become current evidence.
- Title, start, end, timezone, venue, source modification time, retrieval time, canonical URL, and required answer fields normalize into bounded facts. The question controls which fields are returned.
- Matching live fact identities outrank copied synchronized claims. Current-event failure prevents stale matching date/time substitution while unrelated stable ORAS evidence remains available.
- Event URLs come from the server-side event record and are admitted only after URL-policy validation. Member/model text cannot supply a trusted connector URL.
- Missing dependencies, empty lookup, failed lookup, malformed records, and unsafe URLs return bounded outcomes without retry loops or raw errors.
- The evidence boundary excludes `WP_Post`/TEC objects, arbitrary metadata, and attendee/private organizer data.

## WooCommerce qualification

- Generic Observer Pass price requests can return distinct Annual and Daily facts; option-specific requests retain separate fact identities.
- Currency is retained. Current price, availability/stock state, and purchasability are independent facts rather than one collapsed product value.
- Active sale state uses the authoritative sale/current price while retaining regular price only when needed to explain that active sale. Inactive sale fields do not override current price.
- Recognized products/variations normalize through bounded matching. Ambiguous products or variations, malformed values, failed lookup, or unavailable WooCommerce fail safely without static current-value invention.
- Live WooCommerce state outranks only matching synchronized fact identities. A failed Annual-price lookup cannot suppress Daily price, Annual availability, facility, policy, or unrelated event evidence.
- Product URLs are server-derived and URL-policy validated. The evidence boundary excludes raw `WC_Product`/variation objects, arbitrary product metadata, orders, customers, billing/shipping/payment fields, carts, and sessions.
- Static source inspection and automated regression tests confirm that the connector performs read-only product lookup only; no add-to-cart, checkout, order, payment, price update, or other mutation exists.

## PMPro qualification

- The M3 Boolean membership authorizer remains the AI-access boundary. The M5 PMPro adapter is separate and supplies answer context only after an existing authorized server request creates the live request.
- Queries are limited to the authenticated user's own current membership. Browser text cannot choose another user, enumerate members, or establish identity/visibility/admin state.
- Administrator AI allowance does not imply membership. An administrator sees only their own factual PMPro state.
- The adapter returns authoritative active/inactive status and a sanitized current level name when exactly one active level exists. No active level is represented safely.
- With multiple valid active levels, a status question may return active while a level-specific question returns bounded `membership_level_ambiguous`; this valid domain state is not recorded as a connector-health failure.
- The temporary `pmpro_disable_admin_membership_access` filter is installed only around administrator factual lookup and removed with guaranteed cleanup, including thrown lookup failures. Existing caller filters are preserved.
- PMPro live facts legitimately have no canonical URL and therefore emit no invented or empty source link.
- The evidence/model boundary excludes raw PMPro/user records, user IDs, email/contact fields, billing/payment/history data, subscription identifiers, and arbitrary usermeta.

## Live contract, authorization, and provider independence

- Live request identity and allowed visibility derive only from the typed authorized server request. No unauthenticated live endpoint or browser-selectable connector was added.
- Connector selection is deterministic and server-side. There is no model-driven tool selection or connector loop.
- Vendor access remains behind the live request, live connector interface, live result/fact values, and live service. The Answer Orchestrator contains no TEC, WooCommerce, or PMPro API/schema dependency.
- The normalized fact contract supports multiple field-level identities in one request rather than assigning one universal primary fact key to all evidence.

## Precedence, partial failure, and Needs Review

- Same-fact live and synchronized candidates compete under the existing precedence layer; unrelated facts coexist.
- `live_oras_state` wins a matching conflict. Semantically equivalent event instants and numeric prices do not create false conflicts.
- When Events succeeds and WooCommerce Annual price fails in a compound request, the live event fact and bounded Woo failure remain, a synchronized `product:observer-pass-annual:price` substitute is suppressed, and unrelated synchronized observatory-address evidence remains eligible for grounding.
- Multiple failed required facts suppress static substitution only for their own bounded fact-key set. No request-wide static-retrieval shutdown exists.
- Only the exact scanner-managed conflicting artifact (`_oras_ai_managed_by_scan === '1'`) enters Needs Review. Manual artifacts and unrelated siblings remain untouched.
- The linked-source review signal is idempotent and stores only a bounded reason; conflicting values are not copied into review metadata.

## Failure and observability qualification

- Counted operational reasons are allowlisted and include dependency/API unavailable, connector exception, invalid result type, lookup failure, malformed/corrupt vendor data, unsafe canonical URL, and the other bounded operational reasons already defined by the connector contract.
- Valid business, security, or routing outcomes do not count as connector-health failures: no upcoming event, inactive membership, multiple valid PMPro levels, absent/unavailable product state, denied requests, unsupported/historical requests, and ambiguous wording.
- Observability uses exactly three fixed records: The Events Calendar, WooCommerce, and PMPro. Storage is one bounded non-autoloaded option, not an event-by-event log.
- Each connector has its own saturating cumulative failure count, current operational state, last safe reason, and last failure time. Success marks current state healthy without erasing cumulative count or last-failure detail.
- One connector's outcome does not alter another connector's counter. Stored data excludes question text, member/user identity, product/event/member values, provider/vendor payloads, raw exceptions, stack traces, prompts, and evidence bodies.

## Connector Health and ADM-005 interpretation

- **Approved M5 ADM-005 slice:** local WordPress adapters expose read-only installed/dependency availability, operational state, failure count, and last safe failure reason/time.
- M5 does not invent enable/disable settings or configuration values where these local adapters have no actual connector configuration.
- ORAS AI → Connector Health is registered inside the existing admin menu and requires `manage_options`; a non-admin receives no page content.
- It lists all three M5 connectors and safely displays availability, current/never-called state, cumulative count, and last safe reason/time where present.
- The page has no toggles, retries, polling, reset controls, impersonation, raw debugging/vendor/member detail, mutating handler, or frontend member endpoint.

## Local qualification boundary

Qualification used injected fakes, the repository's WordPress test harness, and vendor API/function stubs. This checkout is not a WordPress installation and no production ORAS.org database or installed plugin set was imported; therefore this evidence does not claim live production qualification.

Production deployment verification must confirm:

- installed The Events Calendar, WooCommerce, and PMPro versions remain compatible with the adapter functions exercised by the harness;
- actual ORAS Observer Pass product names, slugs, Annual/Daily variation structure, currency, state, and canonical product URLs match the bounded adapter rules;
- actual AstroBlast/Public Night records, timezone/venue data, publication state, and canonical event URLs match the bounded adapter rules;
- Connector Health availability and bounded failure reporting operate correctly in the deployed WordPress environment.

These environment checks do not change the qualified internal connector contracts or add an M6 feature.

## Explicit deferrals and release state

- M6 astronomy/current-sky/weather providers, observatory time-zone qualification, forecast freshness, and best-night recommendations remain unstarted.
- M7 Fluent Support integration and escalation remain unstarted.
- M8 member actions remain unstarted; validated links continue into normal ORAS/WooCommerce workflows without AI side effects.
- M9 production-release activities remain unstarted.
- No release ZIP, checksum, tag, package, or other release artifact was created.
- Plugin version remains `0.2.1`; M5 is an internal development milestone, not a deployable SemVer release.
