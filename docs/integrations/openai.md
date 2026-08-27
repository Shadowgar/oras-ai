# OpenAI Integration

## Role

OpenAI is the initial provider for:
- ambiguous source classification;
- mixed-source structured extraction;
- ambiguous domain classification;
- conversational answer generation;
- astronomy explanation/reasoning.

## API boundary

Use the OpenAI Responses API behind an internal provider adapter.

## Model policy

GPT-5.6 Luna is the default candidate for cost-sensitive/high-volume workflows. Business behavior is not hard-coded to one model alias. Stronger models may be qualified for specific workflows when evaluation demonstrates need.

## Structured outputs

Classification/extraction calls use strict structured schemas where supported. Free-form classifier prose is not an interface contract.

## Tools

The model may request only tools in an explicit ORAS registry. It receives no arbitrary database or HTTP tool.

## Secrets

API keys are server-side and never delivered to the browser.

## Model change procedure

1. record current model/config;
2. run evaluation corpus;
3. compare grounding, domain compliance, tools, latency, and cost;
4. review regressions;
5. test with admins;
6. deploy with rollback.

## Web search

Unrestricted general web search is disabled by default. ORAS state comes from ORAS systems; current astronomy/weather comes from qualified connectors. Enabling web search later requires a separate ADR/policy.
