# Member Assistant UX

## Entry point

Only authenticated eligible members see the production AI entry point.

ORAS AI provides both of these M4 surfaces, owned and rendered by the WordPress plugin:

- a site-wide, member-only floating launcher labeled **Support**;
- a dedicated member chat page rendered by the `[oras_ai_chat]` shortcode.

Clicking **Support** opens the chat in an overlay/panel without navigating away. The floating panel and dedicated page use the same underlying chat component, conversation transport, authentication, request gateway, response renderer, and backend. The plugin must not require theme `functions.php` changes.

The launcher appears only for eligible authenticated members under the existing authorization boundary. Administrators may receive it according to the existing administrator rules; anonymous and ineligible users do not.

On refresh or navigation between ORAS.org pages, the member's current/latest conversation is restored. **New Chat** starts a fresh conversation. Multiple conversations may exist internally, but M4 does not require a conversation-history/sidebar browser. Desktop uses an overlay panel; mobile may use a near/full-screen panel. Both remain the same component.

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

Sources are rendered directly beneath the corresponding assistant answer. They use only the server-provided source title and canonical URL. The client must not create model-generated or inferred source links.

## Errors

Differentiate:
- temporary AI outage;
- live connector unavailable;
- rate limit/budget reached;
- no authoritative ORAS answer.

Never expose internal prompts, API keys, raw stack traces, or sensitive connector details.
