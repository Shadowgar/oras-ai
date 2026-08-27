# Member Assistant UX

## Entry point

Only authenticated eligible members see the production AI entry point.

Initial preferred surface: a dedicated member page. A floating member-only widget may be added after the dedicated interface is qualified.

Suggested scope copy:

> Ask ORAS AI about ORAS membership, events, the observatory, equipment, policies, Observer Passes, and astronomy or observing questions.

## Answer types

### ORAS answer
Concise answer plus relevant ORAS source/action link.

### Astronomy explanation
Educational answer; external source link is optional unless current/specific data is used.

### Current observing answer
Shows relevant date/time/location context, current sky/weather inputs, and uncertainty.

### Action recommendation
Example: favorable observing night + live Observer Pass availability + canonical purchase link.

### Off-topic refusal

> ORAS AI is limited to ORAS and astronomy-related questions. I can help with ORAS services, events, observing, telescopes, or astronomy.

## Follow-up turns

Conversation context may carry forward, but authorization and domain approval are rechecked on every request.

## Sources

Source links should be close to the claims/actions they support rather than dumped into a large generic bibliography.

## Errors

Differentiate:
- temporary AI outage;
- live connector unavailable;
- rate limit/budget reached;
- no authoritative ORAS answer.

Never expose internal prompts, API keys, raw stack traces, or sensitive connector details.
