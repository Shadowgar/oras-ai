# WordPress Integration

## Responsibilities

WordPress supplies:
- authenticated identity/session;
- admin capability model;
- source content;
- REST/AJAX framework;
- storage host;
- plugin integration surface.

## Requirements

- server-side capability checks;
- WordPress nonce/CSRF controls;
- sanitization and escaping;
- browser-supplied user IDs/membership claims are never trusted;
- source discovery excludes ORAS AI internal post types to prevent recursive ingestion.

## Admin surfaces

Expected:
- Dashboard
- Knowledge Sources
- Knowledge Base
- Needs Review
- Routing / Fluent Support
- AI/provider settings
- Usage/cost
- Connector health
- Audit/scan history

## Cron

WordPress cron may schedule sync, but current event/product/member correctness never depends on a recent cron run. Live systems are queried directly when needed.


## Speaker records

`oras_speaker` records are indexed by default as Event/educational knowledge unless an administrator excludes the source.

Speaker biographies may answer who a presenter is, background/expertise, and other durable presenter information. They are **not** used as the authoritative source for current ORAS Board, officer, or committee-chair positions unless a separate approved ORAS organizational source confirms that role.

Deleted or materially changed speaker records follow the normal source synchronization and retirement rules.
