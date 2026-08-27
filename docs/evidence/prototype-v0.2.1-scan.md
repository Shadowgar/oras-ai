# Prototype v0.2.1 Scanner Evidence

**Evidence date:** 2026-08-16  
**Purpose:** Capture proof-of-concept state that motivated the architecture.  
**Authority:** Prototype evidence only; not production architecture.

The v0.2.1 rebuild reported:

- Sources: 108
- Knowledge: 30
- Live Data: 39
- Needs Review: 4
- Ignored: 35
- Pending: 0

## Positive observations

- WooCommerce products were consistently classified Live Data by WordPress rule.
- `tribe_events` records were consistently Live Data by WordPress rule.
- known template/utility records were ignored by rule.
- classification origin (WordPress rule vs AI) and reason were visible.

## Design gaps identified

- some sources reclassified Live/Ignored still displayed old linked KB IDs, although the derived records were intended to be retired;
- mixed pages such as AstroBlast/About Events could lose useful stable description when the entire source was classified Live;
- active counts needed to distinguish retired scanner records;
- some AI category choices were semantically imperfect.

These observations motivate mixed-source extraction, explicit lifecycle UI/counting, rule-first classification, provenance requirements, and M2 regression tests.


## Speaker-record policy decided during M0 review

Speaker biographies remain indexed by default because they are useful for presenter/event questions. They are categorized as Event/educational knowledge and are not authoritative for current Board or organizational roles unless corroborated by an official ORAS organizational source.


## Privacy/security policy decision during M0 review

Public ORAS privacy and website-security policy pages are retained as searchable Policies & Rules knowledge. Retrieval is intent-targeted so they do not pollute unrelated answers.
