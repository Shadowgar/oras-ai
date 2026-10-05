# Operations Runbook

## Routine monitoring

Watch:
- connector health;
- scanner failures;
- cost/budget alerts;
- Needs Review backlog;
- unusual domain/rate-limit spikes;
- repeated unanswered ORAS questions.

## Changing the model

1. Record current model/config.
2. Run the versioned evaluation corpus.
3. Compare grounding, domain compliance, tool correctness, cost, and latency.
4. Review regressions.
5. Test with admins.
6. Roll to members with rollback available.

## Changing a connector/provider

Run normalized contract tests. Orchestration behavior should not need rewriting because a provider names fields differently.

## Kill switch

Provide an admin switch that:
- disables member AI requests;
- leaves the ORAS website operational;
- preserves knowledge/configuration;
- displays a normal support/contact fallback.

## Degraded mode examples

- OpenAI unavailable → AI unavailable; ORAS website remains functional.
- Weather unavailable → stable ORAS/general astronomy may continue; observing recommendation states weather unavailable.
- Fluent Support unavailable → normal answers continue; escalation shows fallback.
- Scanner unavailable → existing approved knowledge remains; live systems remain queryable.

## Safe installation and member enablement

The historical missing-option default enables member AI. Do not activate a new installation and then disable it: install the plugin inactive and explicitly preseed OFF first. Use the deployment's authorized account and confirmed WordPress path, represented below by `WP_PATH`; this is an operator-supplied value, not a production path inferred by this project.

```bash
wp --path="$WP_PATH" option update oras_ai_member_ai_enabled 0
wp --path="$WP_PATH" option get oras_ai_member_ai_enabled
wp --path="$WP_PATH" plugin activate oras-ai-assistant
wp --path="$WP_PATH" option get oras_ai_member_ai_enabled
```

The installed directory/slug must match the operator's actual installation; substitute that slug in `plugin activate` if it differs. Confirm the first read is `0` **before activation**, then confirm the second remains `0`. Activation and upgrades preserve existing saved ON/OFF, credentials, pricing and ledger data. Reactivation preserves the saved state. Existing installations with a missing option retain their historical default; this work does not authorize changing existing users' enablement policy.

Configure through the existing Settings, Usage & Cost, Astronomy & Weather and Support Routing pages. Local checks report key presence, the raw allowlisted model and matching validated price entries, accounting faults/locks, the AstronomyAPI pair, NWS contact, fixed ORAS site, General/topic routing and the contact fallback URL. They disclose only status, not secrets or mailbox/tag IDs. `Configured` is a local presence/format result, not proof of provider authentication, Fluent Support destination existence, contact-page reachability or production readiness. Validate these later in an authorized deployment qualification. Existing pricing validation remains authoritative; no rates, free-model exception or budget changes are introduced.

Keep members OFF until owner-authorized enablement after qualification. The administrator Test Console uses the same server-side kill switch: an enabled testing window also permits eligible members, so arrange that window explicitly with the owner. There is no administrator bypass. Administrative source scanning remains separate from the member switch, but its paid OpenAI work still obeys the shared budget, reservation and accounting-failure protections. Disable members again after an authorized test window if rollout is not approved.

## Usage metadata maintenance and idle sites

`oras_ai_prune_usage` is one native daily WordPress cron event. Activation registers it; ordinary plugin load registers an `init` callback that repairs a missing schedule even on a same-version installation. Repeated registration is idempotent. The first scheduled run is approximately one day after registration. Deactivation clears this hook only; conversation and escalation hooks retain their own existing deactivation handlers. Reactivation restores scheduling without erasing data.

Keep these claims separate:

1. The callback works: invoke and inspect a maintenance batch in a disposable environment.
2. WordPress has scheduled it: inspect the cron event and next scheduled timestamp.
3. An external scheduler actually invokes WordPress cron without site traffic: verify the deployment's authorized host scheduler and its execution records. Claims 1 and 2 do not establish claim 3.

At deployment, arrange the hosting account's approved scheduler/WP-CLI mechanism to invoke due cron regularly, for example every five minutes. The operator must supply the verified account, absolute WP-CLI executable and WordPress path. No production scheduler or `wp-config.php` is installed/changed by this implementation. The required command, run as that authorized account, is:

```bash
wp --path="$WP_PATH" cron event run --due-now
```

Do not use `--skip-plugins` for scheduled maintenance: the active plugin must register its callback. Inspect registration and bounded status without displaying the ledger:

```bash
wp --path="$WP_PATH" cron event list --fields=hook,next_run_gmt,recurrence
wp --path="$WP_PATH" option get oras_ai_usage_maintenance --format=json
```

Look for `oras_ai_prune_usage`, a daily recurrence, and advancing `last_attempt` / `last_success` timestamps after due execution. Usage & Cost shows equivalent safe schedule and batch status. Record scheduler installation, authorized account/path, command exit status and host execution timestamps. Observe a due run with ordinary site traffic absent before claiming idle-site enforcement. A cron entry without execution evidence is insufficient.

A due event normally waits until the next host scheduler invocation (or WordPress traffic-triggered cron); an outage, disabled cron or missed invocation extends the delay. Each batch examines at most 100 reservations, 100 rejection-month entries and 100 burst timestamps, with at most 100 user buckets per ancillary category. Retained entries rotate to the end so later expired entries are eventually reached. At a stable size, a reservation sweep can need `ceil(total reservation records / 100)` daily passes, including retained young/recovery records, and large nested ancillary buckets can need more passes. Growth, downtime and lock contention can extend that delay. The whole serialized option must still be loaded and stored; the design bounds record processing, not option size or serialization cost. Do not promise deletion at the exact cutoff second or within 24 hours. `last_success` means one batch succeeded, not that all expired records are gone.

For an authorized backlog operation, an operator can invoke a single explicit batch through the registered callback, then inspect counts and repeat a finite operator-chosen number of times with a pause and budget review; do not install a tight retry loop:

```bash
wp --path="$WP_PATH" eval 'do_action("oras_ai_prune_usage");'
wp --path="$WP_PATH" option get oras_ai_usage_maintenance --format=json
```

This explicit callback leaves the recurring cron event intact. Normal unattended operation uses `cron event run --due-now`. No provider or mail calls are made by the usage callback. A low removal count alone does not prove the entire rotating ledger has been inspected.

`busy` means the atomic ledger lock was unavailable: the ledger was left unchanged and a later daily attempt may succeed. Inspect repeated busy/stale status; never delete an unowned lock as an automatic retry. `failed` means mutation/storage failed and the existing ledger safety lock continues blocking paid work. Preserve evidence, reconcile outstanding exposure and follow the accounting recovery procedure in `docs/evidence/m9-site-wide-accounting/verification.md` before an explicitly authorized repair. Maintenance never clears a fault fence or treats unknown tokens as zero cost. Status write failure itself can leave status stale; verify timestamps and WordPress/database health when investigating.

While the plugin is deactivated, its hooks cannot enforce usage, conversation or local escalation retention. The operator remains responsible for retained data: keep the active maintenance path available or arrange a separately authorized cleanup before a prolonged inactive period, then verify backlog processing on reactivation. Do not assume inactive WordPress cron hooks delete data.
