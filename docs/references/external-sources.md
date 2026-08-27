# External Reference Sources

These links are implementation references, not architectural authority. Recheck them when coding because APIs/plugins evolve.

## Documentation pattern reference

- SnakeTracker docs package: https://github.com/Shadowgar/SnakeTracker/tree/main/docs
- SnakeTracker ADR index: https://github.com/Shadowgar/SnakeTracker/blob/main/docs/adr/README.md
- SnakeTracker milestone format: https://github.com/Shadowgar/SnakeTracker/blob/main/docs/roadmap/milestones.md

## OpenAI

- GPT-5.6 Luna: https://developers.openai.com/api/docs/models/gpt-5.6-luna
- Model guidance / Responses API: https://developers.openai.com/api/docs/guides/latest-model

At documentation time, OpenAI describes GPT-5.6 Luna as optimized for cost-sensitive/high-volume workloads and lists Responses API, function calling, structured outputs, and File Search support.

## Fluent Support

- REST API: https://fluentsupport.com/rest-api/
- Action hooks: https://developers.fluentsupport.com/hooks/actions/
- Filter hooks: https://developers.fluentsupport.com/hooks/filters/

At documentation time, Fluent Support documents ticket creation through its REST API and WordPress hooks around ticket creation, responses, customers, and ticket-data handling.

## Version pinning rule

Before implementing against a plugin API:
1. record the installed plugin version;
2. verify the exact supported REST/hook contract;
3. add integration tests;
4. prefer documented interfaces over undocumented database internals.
