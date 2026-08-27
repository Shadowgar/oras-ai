# Test Strategy

## Unit tests

- deterministic source rules;
- domain guard;
- source precedence;
- visibility filters;
- URL validation;
- rate accounting;
- ticket payload construction;
- mixed-source validation.

## Integration tests

- WordPress authorization;
- PMPro eligibility;
- WooCommerce lookup;
- Events Calendar lookup;
- Fluent Support ticket creation;
- OpenAI structured-output parsing;
- astronomy/weather adapter contracts.

## End-to-end tests

An authenticated member asks a representative question and receives a grounded answer/action with expected sources/tools.

## AI evaluation tests

Versioned corpus for:
- domain classification;
- retrieval relevance;
- ORAS hallucination;
- mixed-source extraction;
- answer grounding;
- observing recommendations;
- tool selection.

## Security tests

Prompt injection, authorization, CSRF, XSS, SSRF, secret exposure, rate abuse.

## Representative prompt classes

- stable ORAS FAQ;
- ORAS policy;
- current event;
- product/pass;
- member-specific question;
- general astronomy;
- current sky;
- observing-night recommendation;
- mixed ORAS + astronomy;
- off-topic;
- prompt injection;
- cannot-answer/escalation;
- feedback submission.

Every repeatable production failure should become a regression test/evaluation case.
