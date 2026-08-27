# Admin Experience

## Dashboard

Show:
- active Approved knowledge;
- Needs Review;
- live sources;
- ignored sources;
- retired count separately;
- scan/source errors;
- last successful sync;
- connector health;
- usage/cost summary;
- off-topic/rate-limit counts;
- knowledge-gap candidates.

## Knowledge Sources

Each row should expose:
- source title/type/URL;
- classification;
- classified by WordPress rule or AI;
- category;
- visibility;
- confidence;
- derived KB state;
- source freshness;
- last analyzed;
- reason;
- exclude/rescan/review actions.

If a linked KB item is retired, the UI says **Retired** rather than looking active.

## Needs Review

Central queue for:
- mixed extraction uncertainty;
- low-confidence classification;
- conflicting sources;
- missing source;
- policy ambiguity;
- support-derived knowledge candidates.

## Routing

Admin config maps ORAS AI topic to Fluent Support tags and optional team/agent assignment within one primary ORAS Support mailbox. Routing data is not hard-coded into prompts. Every route has a General ORAS Support fallback.

## Usage and cost

Expose:
- requests/day/month;
- requests by member;
- blocked off-topic requests;
- rate-limited requests;
- token/tool usage when available;
- estimated cost;
- most expensive workflow classes.

## Admin test console

May display:
- domain result;
- retrieval evidence;
- live tools called;
- source precedence;
- provider/model;
- token/cost metrics.

Admin testing must still obey tool security and never reveal secrets.
