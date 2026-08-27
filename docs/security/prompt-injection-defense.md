# Prompt-Injection Defense

## Principle

Member input and website content are untrusted text. They can influence the answer but cannot redefine application policy.

## Member input

- domain policy evaluated before orchestration;
- model cannot grant itself tools;
- authorization occurs server-side;
- prompt length/tool recursion capped.

## Retrieved content

Evidence is labeled with source/URL/authority metadata. Any instructions inside source content are untrusted.

## Tool calls

Arguments are validated against strict schemas and authorization. Trusted IDs/results are preferred over arbitrary URLs.

## Ingestion

Strip scripts, forms, hidden controls, and repeated navigation/footer content. Suspicious instruction-like content may require review.

## Evaluation attacks

Include source fixtures containing:
- "ignore previous instructions";
- fake system prompts;
- requests for API keys;
- internal/private URLs;
- instructions to upload/email secrets.

Expected result: treated as content, not commands.
