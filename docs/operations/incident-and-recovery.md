# Incident and Recovery

## API spend spike

- disable/lower AI quota;
- inspect usage ledger;
- identify account/workflow pattern;
- rotate key if compromise is suspected;
- add regression/abuse control.

## Data leakage concern

- disable affected endpoint;
- preserve minimal audit evidence;
- rotate exposed secrets;
- determine scope;
- follow ORAS incident/privacy procedures.

## Bad knowledge

- retire/exclude affected source/artifact;
- force sync/rebuild;
- correct source where appropriate;
- add regression test;
- do not permanently hand-edit scanner-derived content while leaving the authoritative source wrong.

## Provider outage

Use a qualified fallback only if one exists; otherwise fail gracefully.

## Plugin fatal error

- disable ORAS AI via WordPress/filesystem;
- ensure main ORAS site remains recoverable;
- restore last known-good plugin version;
- preserve database records.

## Backups

Before schema migration or major rebuild:
- WordPress database backup;
- plugin source/version retained;
- configuration recoverable/documented;
- rollback plan recorded.

Every release identifies whether rollback is schema-compatible.
