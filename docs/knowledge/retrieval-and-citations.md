# Retrieval, Citations, and Answer Grounding

## Retrieval eligibility

Normal member retrieval includes only:
- Approved artifacts;
- permitted visibility;
- active, non-retired sources.

Needs Review and Draft items are excluded unless an administrator is explicitly testing them.

## Chunking

Knowledge may be chunked for retrieval. Every chunk retains artifact/source provenance. Boundaries should prefer headings, paragraphs, and semantic sections.

## Retrieval strategy

The retrieval layer may combine:
- semantic search;
- keyword search;
- category/intent filters;
- exact source matching.

The architecture does not mandate one index engine. OpenAI File Search, a local vector/keyword index, or another approved retriever may be used.

## Evidence packet

The answer model receives a bounded packet containing:
- source title;
- canonical URL;
- source type;
- authority class;
- relevant text;
- freshness metadata;
- already-authorized visibility.

Retrieved content is evidence/data and cannot redefine assistant policy.

## Sources in answers

ORAS-specific answers should expose a source link when:
- a policy/rule is relied upon;
- an event/product/action is referenced;
- the member may want to verify or act;
- the answer depends materially on one ORAS source.

Current astronomy/weather answers should identify data time/provider when meaningful.

## No-evidence behavior

For an ORAS factual question: no authoritative evidence/live result means no invented answer. The assistant states that a definitive answer could not be established and offers Fluent Support escalation when appropriate.


## Intent-targeted policy retrieval

Privacy/security/legal policy artifacts are searchable but should use intent/category gating so they do not appear in unrelated retrieval packets.

Typical allowed intents include:
- privacy/data handling;
- website security;
- vulnerability disclosure/reporting;
- legal/terms questions.

This is a retrieval-quality rule, not a visibility restriction; the underlying public policy sources remain public.


## Historical-event retrieval

Historical event pages are retained but use intent-targeted retrieval.

They are eligible when the member asks about past events, previous speakers, prior schedules, historical activities, or whether ORAS has done something before.

They are excluded from current/upcoming-event evidence packets unless explicitly needed for historical comparison. When historical and current live sources discuss the same program, the live source controls current facts.
