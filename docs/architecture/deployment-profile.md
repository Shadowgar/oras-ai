# Deployment Profile

## Initial topology

- existing ORAS WordPress deployment;
- ORAS AI plugin server-side;
- HTTPS;
- existing WordPress database;
- outbound HTTPS to OpenAI and qualified astronomy/weather services;
- existing Fluent Support, PMPro, WooCommerce, and Events Calendar plugins.

No separate daemon is required initially.

## Secrets

Preferred: environment/server secret or `wp-config.php` constant. Protected WordPress option is fallback when operationally necessary. Secrets never reach HTML/JavaScript/Git/routine logs.

## Browser

The browser calls ORAS endpoints only. It never calls OpenAI directly.

## Background work

Long scans use chunked/durable work rather than one long PHP request.

## Future scale

External workers/services may be introduced only when measured operational need justifies an ADR.
