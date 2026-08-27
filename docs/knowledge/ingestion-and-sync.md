# Knowledge Ingestion and Synchronization

## Normal scan

1. Discover eligible WordPress objects.
2. Extract readable source content.
3. Calculate a content hash.
4. Skip expensive reclassification when content/rules/extraction version are unchanged.
5. Apply deterministic source-type rules.
6. Use AI only when deterministic classification is insufficient.
7. Produce Static, Live, Mixed, Ignore, or Needs Review.
8. Update source registry.
9. Create/update/retire derived knowledge.
10. Record scan evidence.

## Rebuild scan

A rebuild re-evaluates every source when:
- classification rules change;
- extraction logic changes;
- structured classifier schema changes;
- a previous bug requires cleanup.

Rebuild may retire scanner-managed knowledge but must not silently alter manual knowledge.

## Idempotency

For the same source hash and extraction version, re-running a completed sync must not create duplicate artifacts.

## Missing sources

A source that disappears is marked missing. Its scanner-managed derived knowledge is retired unless an administrator explicitly preserves it.

## Exclusions

Administrators can exclude sources. Exclusion persists across scans until removed.

## Source normalization

Raw source content may pass through WordPress rendering/shortcodes to obtain human-readable text. The pipeline strips scripts, forms, hidden controls, duplicated header/footer/navigation content, and other non-knowledge material.

## Scheduled sync

Incremental scheduled sync may be introduced after M2. Live event/product correctness must not depend on recent scanner execution because those values are queried from the authoritative system at answer time.
