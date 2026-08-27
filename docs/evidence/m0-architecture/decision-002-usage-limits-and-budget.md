# M0 Decision 002 — Usage Limits and Site-Wide AI Budget

**Status:** Resolved during M0 review

## Initial member limits

| Limit | Value |
|---|---:|
| Allowed AI questions per member per day | 25 |
| Allowed AI questions per member per month | 150 |
| Burst limit | 5 requests/minute |
| Site-wide OpenAI spend warning | $10/month |
| Site-wide OpenAI hard stop | $20/month |

## Counting policy

An **allowed AI question** is a member request that proceeds into the primary ORAS AI answer workflow.

Obvious off-topic requests that are rejected locally before the primary AI workflow do not consume the daily/monthly AI-question allowance. They still count toward abuse and rate-limit telemetry so repeated attempts can be throttled.

The site-wide hard stop applies regardless of remaining individual member quota. Raising the hard stop later requires an administrator configuration change and should be based on observed usage/cost.

This remains part of the Draft M0 package until the complete M0 architecture is owner-approved and frozen.
