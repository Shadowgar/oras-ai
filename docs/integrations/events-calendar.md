# The Events Calendar Integration

## Role

The Events Calendar is authoritative for current ORAS event records represented as `tribe_events`.

## Default rule

`tribe_events` → Live Data.

## Common queries

- next AstroBlast;
- next Public Night;
- events this weekend;
- event start/end;
- venue;
- canonical event page;
- event conflicts with a planned observing night.

## Event series

A `tribe_event_series` source may contain durable program description. It may become knowledge only when current dates/availability are excluded.

## Conflict behavior

If an overview page date conflicts with the event record, the event record wins and the static source is flagged for review/sync.


## Historical pages

Past event pages and archived schedules may remain indexed as historical knowledge. They are not a replacement for current Events Calendar records and do not answer present/future schedule questions.

Historical retrieval is intent-gated and lower priority than current live event data.
