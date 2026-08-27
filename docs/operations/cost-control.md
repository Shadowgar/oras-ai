# Cost Control

## Goal

Protect ORAS funds while keeping the assistant useful.

## Initial production limits

- **25 allowed AI questions per member per day**
- **150 allowed AI questions per member per month**
- **5 requests per minute burst limit**
- **Site-wide OpenAI spend warning at $10/month**
- **Site-wide OpenAI hard stop at $20/month**

Obvious off-topic requests rejected locally before the primary AI workflow do **not** consume the member's daily/monthly AI-question quota, but they still count toward abuse/rate-limit telemetry so repeated misuse can be throttled.

The site-wide hard stop is a safety backstop. An administrator may later raise it through an audited configuration change after reviewing actual usage and cost.

## Required controls

### Authentication
No anonymous AI at launch.

### Member quota
Configurable daily/monthly quota. Numeric defaults are owner configuration, not architecture constants.

### Burst limiting
Prevent automated rapid request bursts even when a user has remaining quota.

### Prompt size
Reject oversized prompts. ORAS AI is not a document-processing service for unrelated material.

### Response budget
Routine ORAS answers use bounded output. Long educational astronomy answers may have a larger but still controlled budget.

### Efficient ingestion
- deterministic rules first;
- AI only for ambiguous sources;
- source hashes;
- unchanged sources skipped.

### Bounded retrieval
Do not send entire pages/site dumps when a few chunks answer the question.

### Short-lived caching
Cache appropriately stable astronomy/provider results only while freshness remains valid.

### Usage accounting
Track by member, workflow, model, tool, and period.

## Budget exhaustion

Fail closed with a clear service-unavailable/limit message. Do not silently switch to a more expensive model or repeatedly retry.
