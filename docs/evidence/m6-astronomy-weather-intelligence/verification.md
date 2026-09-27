# M6 — Astronomy/weather intelligence verification

Date: 2026-09-27 (UTC). **M6 STATUS: READY TO CLOSE.**

Implementation HEAD before M6 closure documentation:
`65bdab0445adf0d548e49d4ad497305987f12aaa` — `Fix M6 current-data qualification boundaries`.

This records implementation qualification, including the former closure blockers. No closure commit exists. No deployment, release package, version bump, M7+ implementation, or Member Hub changes were made. ASTRO-007 remains deferred. Credentialed AstronomyAPI operation and installed-site/model acceptance remain explicit deployment verification items below.

## Baseline and fresh checks

- Branch `m6/astronomy-weather-intelligence`; clean implementation worktree before these documentation edits.
- Origin ahead/behind `0/0`; `git ls-remote origin refs/heads/m6/astronomy-weather-intelligence` independently matched the implementation HEAD.
- Plugin header, runtime constant, and package version: **0.2.1**. PHP requirement: **>=8.0** in plugin and Composer.
- Fresh baseline and post-documentation runs: `npm run lint:php` **179 files**, `npm run lint:js` PASS, `npm test` **493 PHP tests + 26 frontend assertions**, `npm run quality` PASS, `git diff --check` PASS. Additional `node --check assets/chat.js` PASS; the npm JS check covers `assets/scanner.js`.
- Full test execution includes all M1–M5 regressions. No new implementation or permanent tests were necessary for this qualification.
- Two inherited PHP warnings remain; passing checks are not represented as warning-free. See warning disposition below.

Supporting Member Hub, read only:
`/home/rocco/projects/ORAS-Member-Hub`, branch `feature/oras-ai-score-contract`, clean at
`ac87426577370af63f441aea5d119fdd4ed1c784` — `Add explicit observing score context API`.
That commit introduces `compute_observing_score_for_context(...)`. Plugin version **0.2.15**, declared PHP >=7.4; ORAS AI's >=8.0 requirement remains controlling for this integration.

## Frozen requirement decision

Sources reread: [functional requirements](../../requirements/functional-requirements.md), [non-functional requirements](../../requirements/non-functional-requirements.md), [ADR-0012](../../adr/ADR-0012.md), [normalized astronomy/weather contract](../../integrations/astronomy-weather.md), M0 decisions [008](../m0-architecture/decision-008-astronomy-provider-deferred.md) and [009](../m0-architecture/decision-009-weather-provider-deferred.md), [test strategy](../../quality/test-strategy.md), and [acceptance catalog](../../quality/acceptance-test-catalog.md).

Full paths in the following tables are relative to the repository root. Abbreviated class filenames refer to `includes/`; abbreviated astronomy test filenames refer to `tests/astronomy/`. Test names are permanent, executable evidence; supplemental fresh probes are described separately.

| Requirement | Status | Implementation and executed evidence |
| --- | --- | --- |
| ASTRO-001 | PASS | Stable astronomy bypasses current planning: `includes/class-oras-ai-answer-orchestrator.php`; `tests/answer/AnswerOrchestratorTest.php` general-astronomy routing, `tests/astronomy/ObservingPlannerTest.php` untimed-general-question regression; E2E A below makes zero NWS/planet calls. |
| ASTRO-002 | PASS | `class-oras-ai-current-astronomy-service.php` dispatches normalized local Sun/Moon, explicit OpenNGC targets and allowlisted planet requests. `LocalSunMoonProviderTest.php`, `HorizontalPositionCalculatorTest.php`, `OpenNGCAstronomyProviderTest.php`, `AstronomyApiPlanetProviderTest.php` pass fixed numerical, UTC/site, response/date/path and bounded-horizon assertions. |
| ASTRO-003 | PASS | `class-oras-ai-current-weather-service.php` and `class-oras-ai-nws-weather-provider.php` preserve current/forecast designation and provider times. `tests/weather/NWSWeatherProviderTest.php` and `CurrentWeatherServiceTest.php` pass, supplemented by fresh live NWS observation/forecast normalization. |
| ASTRO-004 | PASS | `class-oras-ai-observing-planner.php` evaluates overlap midpoints, same-instant Moon/targets, and authoritative Member Hub scores. `ObservingPlannerTest.php` tests alignment, ORAS crossover, mixed Observer Pass, both weekend nights, interval comparisons/ties, and isolated failures. Real Member Hub parity and E2E D–J pass. No invented nightly aggregate. |
| ASTRO-005 | PASS | Weather evidence contains issue/valid/freshness context; `class-oras-ai-grounded-context-assembler.php` instructs current answers to disclose uncertainty and forecast time. Weather/planner tests assert valid intervals, unavailable seeing/transparency, and whole-night limitations. This proves grounded context and orchestration; production model wording remains deployment acceptance. |
| ASTRO-006 | PASS | Failed current facts are bounded; no model-memory substitute. `CurrentAstronomyServiceTest.php`, `CurrentWeatherServiceTest.php`, `ObservingPlannerTest.php`, and `M6QualificationCorrectionTest.php` cover missing/stale provider time, provider failures, below-horizon facts and unresolved targets. Fresh stale/missing-time E2E probes return `no_evidence`, zero model calls. |
| ASTRO-007 | DEFERRED | No saved equipment/preferences implementation. |

The frozen contract requires current data/calculations, reproducible outputs, adapter qualification and bounded failure; it does not prescribe a credentialed live AstronomyAPI acceptance test as an M6 gate. The test strategy explicitly includes astronomy/weather adapter contracts; deployment configuration owns server-side secrets. On that basis, the documented/fixture-qualified adapter satisfies the implementation gate while authenticated service operation remains deployment verification. This is an interpretation of those requirements, not an explicit live-test waiver or a claim that the vendor account has been tested.

| Inherited requirement | Result and support |
| --- | --- |
| AUTH-001–005 | PASS: request gateway authenticates and checks configured PMPro eligibility before execution; visibility precedes retrieval; admin capabilities remain enforced. Authorization, eligibility, retrieval and capability-boundary suites pass. |
| DOMAIN-001–006 | PASS: `tests/domain/DomainGuardTest.php`, answer-orchestration and planner tests retain ORAS/astronomy scope, per-turn guarding, refusal and observing-weather routing. |
| RET-001–008 | PASS: retrieval/source-precedence tests retain visibility, canonical links, fact-specific authority, relevant policy/history gating and bounded insufficient evidence. Mixed M5/M6 context retains both families. |
| Current-data UX / UX-003,005,006 | PASS: timestamped current/forecast context, bounded errors and canonical ORAS action links; current facts do not fabricate URL citations. Frontend and chat-UI tests pass. |
| ADM-005 M6 slice | PASS: `tests/admin/M6ProviderAdminTest.php` and config tests cover manage_options, nonce, credential/contact validation, protected storage and sanitized audit. Other milestone administration is not newly claimed. |
| COST-007 | PASS: fixed-slot bounded NWS reuse; request-local astronomy deduplication; one all-body call per sampled instant, no seven-body fan-out. |
| NFR-SEC-001–007 | PASS in the implemented scope: fixed site/host/routes, allowlists, server credentials, minimized untrusted context, authorization/CSRF, safe logs and admin audit. Security and M6 provider tests pass. This is not a new exhaustive security certification. |
| NFR-REL-001–005 | PASS: optional providers fail independently; bounded transport and no unbounded retry; inherited scanner repeatability/sync/retired-evidence tests remain green. |
| NFR-OBS-002 | PASS: four fixed M6 provider identities, count capped at 999999, safe reason/state/last-failure timestamp; normal unavailable fields/freshness states do not automatically count as operational failures. |
| NFR-PERF-002 | PASS: measured bounded multi-source paths below; `assets/chat.js` retains pending “Thinking…” state throughout the request, with accessible `role=status` in chat UI. Stage-specific progress is not a frozen requirement. |
| ADR-0012 | PASS: current sky/weather originates in normalized provider data or deterministic calculations; source identity and time remain attached. |

## Former blockers A–J

Every permanent regression was rerun in the 493-test suite.

| Case | Result |
| --- | --- |
| A. Good-night routing | PASS: M6 planning supplies sky/Moon/weather/score evidence; unavailable current facts do not fall through to model-memory synthesis. |
| B. Mixed Observer Pass | PASS: both product/action evidence and current observing evidence survive source reconciliation; tests isolate failures on either side. |
| C. Weekend / multi-night | PASS: both nights are fetched with distinct dusk/dawn windows and interval-specific scores. Only best intervals are compared, ties retained. Planner returns `partially_grounded` because no authorized whole-night aggregate exists. The answer transport can still succeed in explaining that limitation. |
| D. Active night | PASS: 22:00 EDT on September 9 uses `2026-09-10T02:00:00Z` → following dawn and consults NWS. Pre-dawn, exact dusk/dawn, advancing clock, spring/fall DST, named dates and unrelated stale intervals also pass. |
| E. Seven planets | PASS: 8,515 serialized provider-input bytes; all Mercury/Venus/Mars/Jupiter/Saturn/Uranus/Neptune represented. |
| F. Broad current sky | PASS: 8,507 bytes with bounded planet/Moon facts. Permanent catalog spy prohibits a broad OpenNGC lookup. Explicit M31 resolution remains available separately. |
| G. Stale planet time | PASS: September 1 positions rejected for September 9/10 requests. |
| H. Missing planet time | PASS: bounded unknown/no evidence; requested time is never substituted as provider evidence. |
| I. NWS zero overlap | PASS: endpoint equality excluded by strict `max(start) < min(end)`. Point containment is `start <= point < end`; fresh/cache/adjacent-field cases pass. |
| J. Planet paths | PASS: exact `/api/v2/bodies/positions/jupiter` and `/api/v2/bodies/positions`; all seven canonical single paths, fixed queries and pre-HTTP rejection of unsupported/injected IDs pass. |

Permanent coverage is in `tests/astronomy/ObservingPlannerTest.php`, `M6QualificationCorrectionTest.php`, `AstronomyApiPlanetProviderTest.php`, and `tests/weather/CurrentWeatherServiceTest.php` / `NWSWeatherProviderTest.php`.

## Numerical-reference qualification and provenance

Locked tolerances were not widened. Re-execution at the authoritative ORAS site `41.321903, -79.585394`, elevation `432.816 m`, America/New_York:

| Quantity / fixed reference | Measured absolute error | Locked bound |
| --- | ---: | ---: |
| Sunset / USNO September 9, 2026 | 141 s | 300 s |
| Astronomical dusk / USNO | 167 s | 300 s |
| Astronomical dawn / USNO | 95 s | 300 s |
| Moonrise / USNO | 497 s | 600 s |
| Moonset / USNO | 400 s | 600 s |
| Moon altitude / USNO 12:00Z | 0.010435° | 1° |
| Moon azimuth / USNO 12:00Z | 0.825952° | 1° |
| Moon illumination | 0.000975 fraction | 0.02 |
| ICRS → horizontal, M31 / Astropy-ERFA | altitude 0.002336°, azimuth 0.003673° | 0.1° each |
| NGC 4565 / Astropy-ERFA | altitude 0.001645°, azimuth 0.003828° | 0.1° each |
| M42 / Astropy-ERFA | altitude 0.004623°, azimuth 0.003287° | 0.1° each |
| AstronomyAPI fixture normalization | altitude/azimuth equal supplied 31.25° / 145.5° | 0.01° each |

Independent fixed expectations and below-horizon cases remain in `LocalSunMoonProviderTest.php`, `HorizontalPositionCalculatorTest.php`, and `AstronomyApiPlanetProviderTest.php`. These are qualification at the locked samples, not a claim of high-precision ephemerides over every epoch.

SunCalc **1.0.1**, commit `8b8ca60f8af20d38c00121615beab999ca5dcd55`, is exactly locked. All six installed `src/` files were compared byte-for-byte with a freshly downloaded archive of that commit. SHA-256s:

```text
src/AzAlt.php     7d0903f044db0aa1bc99940b5c7c96051b4ab4c7ac4d37e533d8291a52d7880d
src/AzAltDist.php e60cf7a97f10622b6dec5838cf874d114bbc04a90df693ffc53a28842bb72f96
src/DecRa.php     172d232fe15799e94cf18ad68965eb1a6811cfdb8cda2169ea41b32836628013
src/DecRaDist.php d79615a6705c26affbd0225fe4605e4b754278ef3caa89e367d1ea8287a3e091
src/SunCalc.php   e29739d8cdd0c5a7ec5409704d0a2253d0d1fc77c6923a45adef77c5b53b0080
src/Utils.php     3439ad63cb004b687ae0fc01aa57bc3feaa0d9ee619191ee8194574197184954
```

The local Moon adapter documents its extensions: [pinned SunCalc base](https://github.com/tuxonice/suncalc-php/blob/8b8ca60f8af20d38c00121615beab999ca5dcd55/src/Utils.php), [Schlyter lunar perturbation series](https://stjarnhimlen.se/comp/tutorial.html#7), dominant lunar-distance terms referenced to [Williams lunar-ranging material](https://ilrs.gsfc.nasa.gov/lw13/docs/papers/sci_williams_1m.pdf), and Meeus, *Astronomical Algorithms*, 2nd edition, chapter 40, pp. 279–280 for topocentric parallax. Geometry intentionally omits atmospheric refraction. Horizontal conversion documents [IAU SOFA](https://www.iausofa.org/current_C.html) `iauPfw06`, `iauFw2m`, `iauEra00`, `iauGmst06`; UTC approximates UT1 within the qualified product tolerance. References were inspected in the implementation; the independent locked numerical fixtures are the accuracy acceptance evidence.

OpenNGC commit `da90466031b0372c896588b85be6016c617e205b`; format/index version **1**, **14,026 objects**, **16,353 aliases**:

```text
NGC.csv      be150bdaa1997dacbcb39f303074403edec7a953b589b36d5f1c4522c0cc6fae
addendum.csv 1d8f0914e643ada325a5a94d88d8fefad6a4937a2f77cc34f21483af22b11983
```

Both source hashes were verified and `tools/build-openngc-index.php` freshly regenerated all **33 PHP files**, byte-identical to committed data. Runtime uses deterministic local shards with no catalog network call. Unknown/ambiguous/missing/corrupt entries fail boundedly.

## Live NWS qualification

A fresh read-only run used the production adapter and its URL, timestamp, normalization, size and cache checks, with a bounded cURL bridge for WordPress HTTP in the standalone harness. HTTPS only, redirects disabled, ten-second timeout, identifying User-Agent `ORAS AI Assistant/0.2.1 (https://www.oras.org/contact/)`. This is live adapter qualification, not a test inside deployed WordPress.

Trusted run instant: **2026-09-27T04:47:31Z**. Four HTTP calls, all 200, no transport errors:

| Path | Result | Transport latency |
| --- | --- | ---: |
| `/points/41.3219,-79.5854` | PBZ office, grid 87,108; mapping links matched fixed constructed paths | 102.12 ms |
| `/gridpoints/PBZ/87,108/stations?limit=8` | bounded station discovery selected KFKL | 89.25 ms |
| `/stations/KFKL/observations/latest?require_qc=true` | provider time 04:30:00Z, age 1,051 s, within 5,400 s | 155.14 ms |
| `/gridpoints/PBZ/87,108` | forecast issued 04:41:37Z; nine positively overlapping snapshots for the requested eight-hour window | 647.06 ms |

Normalized current result and forecast result: **success**. Total current discovery/normalization: **350.21 ms**; forecast using cached mapping: **650.79 ms**. Provider degC, km/h, percent and metres normalized to °C, m/s, percent and metres. Current cloud/probability/gust optional nulls remained null; seeing/transparency remained unavailable. Provider valid intervals were retained, not rewritten. No transient weather values were added to permanent tests.

## AstronomyAPI contract and deployment status

[Current provider documentation](https://docs.astronomyapi.com/endpoints/bodies/positions) confirms single/all-body paths, observer coordinates/date/time and Basic authentication. Shared normalization supports the documented table/entry/cells and rows/body/positions envelopes. `horizontal` is canonical; equal `horizonal` duplicates are tolerated, conflicting/malformed duplicates rejected, historical-only payloads bounded unknown. The exact provider date must match the request's serialized whole second after UTC/offset normalization; no nonzero freshness tolerance or request-time fallback.

Documented route contract **PASS**. Fixture qualification **PASS**. Live authenticated verification **NOT PERFORMED**. No usable credentials were found in this process environment, expected local/ancestor configuration, or checked local wp-env configuration; no running local WordPress options database was available. No production credential state is claimed. No credentials were printed, written, or requested.

Single target makes one HTTP call at an instant. An all-seven request makes one all-bodies call at an instant. The bounded tonight planner has five distinct periods in the representative fixture, hence five all-bodies calls; no persistent cross-request planet cache is introduced. Account access, vendor pricing/quota/terms and real network latency remain deployment checks, not invented measurements.

## Real Member Hub integration

Executed the committed `scripts/observing-score-contract-checks.php`: **12 checks passed, 0 failed**. These demonstrate explicit evaluation time, explicit Moon horizon, UTC/local equivalence, stable machine category, returned model/method, legacy formula compatibility, missing-smoke behavior and bounded invalid inputs without provider fetching.

A supplemental standalone harness loaded the actual committed conditions-service class, then compared direct API output with `ORAS_AI_Member_Hub_Score_Adapter` for four cloud/precipitation/wind/Moon cases. Every score, category, model, method, confidence, limiter and threshold-profile field matched. Scores were **72, 72, 29, 72**; missing-smoke cap remained **72**, confidence **low**, model `v4-scientific-audit-tier-c`, method `tier-c-audit-2026-06`. These cap/threshold values were observed from Member Hub, not copied into ORAS AI.

The adapter calls only `compute_observing_score_for_context(...)`; it does not call `get_payload()` or reproduce the formula/thresholds. It passes m/s × **2.2369362921** as mph, exact evaluation instant, same-instant/site Moon illumination and horizon, empty smoke values with explicit missing health. `ObservingScoreAdapterTest.php` additionally asserts mapped inputs/output and absent/incompatible/malformed service behavior. Failure leaves independent weather/astronomy/ORAS evidence available.

## Representative end-to-end orchestration

Real ORAS AI production orchestration/adapters and real committed Member Hub calculations; deterministic provider transports and answer-model fixture. These prove routing, admission and model context, not live model prose or browser deployment.

| Case | Request | Answer / planner | Context bytes | NWS / planet HTTP calls |
| --- | --- | --- | ---: | ---: |
| A | What is a globular cluster? | success / no current planning | 740 | 0 / 0 |
| B | Where is Jupiter right now? | success / current astronomy | 1,425 | 0 / 1 |
| C | What is the weather at ORAS right now? | success / current weather | 1,738 | 3 / 0 |
| D | Is tonight a good night to observe at ORAS? | success / grounded | 8,821 | 2 / 0 |
| E | Can I see M31 tonight? | success / grounded | 9,103 | 2 / 0 |
| F | What planets can I see tonight? | success / grounded | 8,515 | 2 / 5 |
| G | What can I see tonight? | success / grounded | 8,507 | 2 / 5 |
| H | Is tonight good for observing at ORAS and can I buy an Observer Pass? | success / grounded | 9,236 | 2 / 0 |
| I | Which night this weekend looks best for observing? | success / partially_grounded | 9,084 | 3 / 0 |
| J | Good-night question at 22:00 EDT after dusk | success / grounded | 7,813 | 2 / 0 |

Before-dusk requests use dusk → following dawn; active nights use current instant → dawn, including pre-dawn. Explicit targets resolve deterministically; positive altitude is geometric horizon status, not guaranteed observability. Weather, Moon and target calculations align at overlapping interval midpoints. Member Hub owns scores. Neither seeing/transparency nor a whole-night aggregate is fabricated.

## Context bounds, isolation and caching

The global serialized provider-input cap remains **16,000 bytes**; text admission remains bounded at **6,000 bytes** (`strlen`, despite the constant name). F/G each admitted **69 evidence items** with all seven planets and Moon represented. Final rendering groups identical astronomy source metadata only after reconciliation. Permanent assertions preserve every admitted relevant-text string and canonical packet identity, verify deterministic output and intact single-target detail, and reject raw payloads. The existing relevance budget can omit lower-priority items; qualification does not claim that every fact for every possible period enters every bounded prompt. No planet is arbitrarily removed to make these cases fit.

Partial-failure tests pass for NWS outage with astronomy/ORAS retained; planet outage with local/catalog siblings retained; missing/corrupt OpenNGC; unresolved target; missing SunCalc with plugin still loaded; absent/incompatible Member Hub; stale observation; expired and zero-overlap forecasts; malformed sibling periods; stale/missing planet times; and missing smoke. A fresh malformed-period probe retained its valid sibling, while expired and exact-boundary probes returned `forecast_interval_unavailable` with zero values. Current-data model-memory substitution, raw payloads, secrets and exceptions remain excluded.

NWS maximum reuse: point/station mappings **24 h**, local observation **5 min**, provider observation age **90 min**, local forecast **10 min**, additionally bounded by response cache headers. TTL equality expires. Fresh/cache forecast selection and point/field lookup use half-open semantics. An expired cache cannot become a stale-on-network-error current fallback; invalid grid mapping allows one bounded rediscovery. `NWSWeatherProviderTest.php` covers these boundaries. Astronomy has request-local deduplication, no persistent AstronomyAPI cache. OpenNGC runtime has no network lookup.

## Security, privacy, administration and progress

Current-data requests always construct the authoritative server site. Members/models cannot select provider, host, raw path or coordinates. Planet IDs are allowlisted before transport; injected/encoded paths are rejected. Fixed HTTPS endpoints, no redirects, bounded transport timeouts, the NWS response-size limit, planner period limits and secret-only Authorization headers remain intact. No unrestricted web-search capability is exposed to the answer model.

The fresh full suite covers PMPro/authentication, admin capability/nonce, kill switch, quotas, sensitive-input controls, output escaping, visibility and untrusted evidence. Normalized NWS/AstronomyAPI/OpenNGC/Member Hub facts exclude raw internals and stacks. Fixture model contexts were inspected for raw/secret sentinels as well as the permanent security assertions.

`AstronomyObservabilityTest.php` and `M6ProviderAdminTest.php` verify bounded counts/state/reasons and manage_options protection. Health storage contains last failure time but no request/question/member identity, credentials, raw payload or weather history. The current admin table displays state/count/reason; timestamp persistence does not imply a displayed timestamp column. Seeing/transparency absence, optional nulls and non-operational freshness states are not blindly counted as failures.

The chat maintains pending accessible status until orchestration completes. The 26 frontend assertions and existing PHP chat tests remain green; this task does not claim fresh real-browser acceptance.

## Latency and cost

Environment: Linux WSL2 x86_64, Intel i7-11800H, 16 logical CPUs; PHP CLI **8.3.6**, Node **22.22.0**. Local timing uses `hrtime(true)`, five warmups then **30 samples** per path, fixture setup outside timed regions; median and nearest-rank p95. Transport/model fixtures do not simulate network latency.

| Timed path | Median ms | p95 ms |
| --- | ---: | ---: |
| Local Sun/Moon | 0.1455 | 0.2069 |
| OpenNGC + horizontal calculation | 1.1466 | 1.2722 |
| AstronomyAPI seven-body fixture normalization | 0.1286 | 0.1844 |
| Five-period planner, fixture transport, real Member Hub | 28.8498 | 35.3158 |
| Planner + orchestration, fixture model, real Member Hub | 29.2072 | 36.0379 |

Live NWS has one observation path and one forecast path sample, as separately recorded above; no network percentiles are inferred. No live AstronomyAPI or OpenAI latency is claimed.

Local Sun/Moon and OpenNGC have no runtime API fee. [NWS documents no usage fee](https://www.weather.gov/documentation/services-web-api), with rate limits. AstronomyAPI production account pricing/quota was not established and is not invented. The M3 defaults remain a **$10 warning / $20 hard stop**, verified by cost configuration/execution tests; these are OpenAI accounting controls, not an assertion that a separate provider account is capped by that ledger.

## Licensing, reproducibility and warnings

- ORAS AI declares **GPL-3.0-or-later**, PHP >=8.0.
- SunCalc 1.0.1 declares **GPL-2.0-or-later**, PHP ^8.0; pinned source and installed license inspected. Attribution remains in `THIRD_PARTY_NOTICES.md`.
- OpenNGC adapted lookup data remains **CC BY-SA 4.0**, attributed to Mattia Verga/contributors, with full license in `data/openngc/CC-BY-SA-4.0.txt`, source hashes and transformation recorded. It is not relabeled as original GPL code.
- `git ls-files vendor` returns no tracked files. Composer lock is consistent; `composer install --dry-run --no-dev --no-interaction` finds nothing to install/update/remove on this platform. Missing dependency is bounded and the plugin still loads, as asserted in the permanent SunCalc test.
- `composer validate --strict` exits **1 solely for its generic exact-version-constraint warning**; schema is valid. The exact 1.0.1 pin is intentional qualification policy, not drift to repair. This is distinct from the passing required npm quality command.
- **Warning disposition B — nonblocking existing debt:** `includes/class-oras-ai-current-weather-service.php` accesses absent trailing optional regex captures at implementation lines 197/207. PHP yields null; `local_time()` casts to string and follows the intended no-meridiem handling. Existing range/point tests assert correct UTC results. No correctness defect was demonstrated, so no cosmetic code change was made. Production should keep PHP warning display out of REST output as part of installed-site configuration.

## Remaining deployment verification and closure scope

No frozen M6 implementation blocker remains in this qualification. Before enabling the feature on the installed site:

1. Configure AstronomyAPI credentials privately; make one corrected single-body call and, if needed, one all-body call. Verify authentication, provider timestamp agreement, UTC/local conversion, seven-body filtering, coordinate normalization, bounded errors and measured network latency. Confirm account terms/quota/pricing; do not extrapolate fixture success to live service success.
2. Configure the operator-approved NWS identifying contact and verify outbound connectivity/cache behavior in the WordPress runtime. This standalone live run does not establish production network policy or future weather-service availability.
3. Install production Composer dependencies from the pinned lock and the compatible committed Member Hub API. Verify installed WordPress/PMPro/WooCommerce behavior, permissions, model configuration, source/action presentation, progress/errors and actual answer uncertainty/freshness in browser/model acceptance.
4. Verify production logging/error display and budget configuration; retain secret/server boundaries. No release package was built here.

These are deployment/configuration/service-operation checks, not permission to claim them complete. M6 implementation can close; broader production release gates remain later milestones.

## Evidence locations and reproduction

Permanent evidence is the implementation HEAD, tests and source/provenance references above. Fresh supplementary logs/scripts are local review artifacts under `/tmp/oras-ai-final-qualification/`:

- `baseline-{lint-php,lint-js,test,quality}.log` and `final-{lint-php,lint-js,test,quality}.log`;
- `e2e.php`, `e2e.log`, `context-A.json` through `context-J.json` (deterministic fixtures only);
- `live-nws.php`, `live-nws.log` (bounded live run; no secret credentials);
- `member-hub-contract.log`, `member-parity-and-expiry.php`, `member-parity-and-expiry.log`;
- `measure.php`, `measure.log`, `suncalc-provenance.json`, `openngc-build.log`, rebuilt index;
- Composer validation/dry-run logs and final diff/status records.

The temporary artifacts are not test dependencies. The permanent suite reruns with `npm run quality`; the external Member Hub contract checks run with `php /home/rocco/projects/ORAS-Member-Hub/scripts/observing-score-contract-checks.php`. OpenNGC rebuild accepts the two source CSVs only if their recorded SHA-256 values match. No frozen requirements were rewritten and no acceptance/traceability rewrite was needed: the existing AT-ASTRO mapping is resolved by the evidence above.
