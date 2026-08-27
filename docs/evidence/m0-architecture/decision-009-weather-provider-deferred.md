# M0 Decision 009 — Weather Provider Selection Deferred to M6

**Status:** Resolved during M0 review

## Decision

The architecture does **not** select a specific weather API/vendor during M0.

M0 instead freezes the required weather/observing capability contract and normalized Weather connector boundary.

The actual provider is selected during **M6 — Astronomy and Weather Intelligence** after qualification.

## Required capability contract

The selected M6 weather implementation must provide, at minimum:
- cloud cover;
- precipitation probability/type;
- temperature;
- wind speed and gusts;
- humidity when useful;
- visibility;
- forecast issue/freshness time;
- forecast valid time;
- astronomy-specific seeing/transparency when a trustworthy source is available.

## Forecast behavior

ORAS AI must:
- distinguish current conditions from future forecasts;
- preserve forecast-valid/freshness context;
- communicate uncertainty when material;
- avoid presenting long-range forecasts as guarantees;
- use astronomy-relevant weather metrics when qualified.

## Selection criteria

- forecast quality for the ORAS observing site;
- reliability;
- predictable cost;
- licensing compatible with ORAS use;
- machine-readable stability;
- freshness/horizon;
- astronomy relevance;
- time-zone correctness;
- testability.

The provider remains behind ORAS AI's normalized Weather connector so later provider changes do not require rewriting answer-orchestration logic.

This remains part of the Draft M0 package until the complete M0 architecture is owner-approved and frozen.
