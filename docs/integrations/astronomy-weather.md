# Astronomy and Weather Integration

## Status

Astronomy provider selection is deliberately deferred until M6.

## Astronomy provider decision

M0 freezes the **capability contract**, not a vendor/library.

The selected M6 implementation must be able to provide or calculate, at minimum:
- current celestial positions for the ORAS site;
- sunset and twilight;
- Moon phase, rise/set, and altitude;
- planet visibility;
- target altitude/azimuth or rise/transit/set as needed;
- time-zone correct results;
- reproducible/testable outputs.

Selection criteria include:
- astronomical accuracy;
- reliability;
- nonprofit/commercial licensing compatibility;
- predictable cost;
- stable machine-readable interface;
- time-zone correctness;
- testability;
- maintainability.

The implementation must remain behind the normalized Astronomy connector so switching providers does not require rewriting orchestration logic.


## Astronomy normalized contract

Desired data:
- observer coordinates/time zone;
- sunset and civil/nautical/astronomical twilight;
- Moon phase/illumination/rise/set/altitude;
- target RA/Dec when needed;
- target altitude/azimuth over time;
- planet altitude/azimuth;
- rise/transit/set;
- authoritative event/phenomenon metadata.

## Weather normalized contract

Desired data:
- issue/valid time;
- cloud cover;
- precipitation;
- temperature;
- humidity when useful;
- wind/gust;
- visibility;
- optionally astronomy seeing/transparency from a qualified source.

## Observatory location

Use configured authoritative ORAS observatory coordinates, not model memory.

## Observing recommendation

A recommendation may combine ORAS access/event constraints, pass availability, darkness, Moon, targets, cloud/precipitation/wind, and seeing/transparency when available.

## Uncertainty

Forecasts are uncertain. Answers state forecast horizon/time and material caveats.

## Provider selection criteria

- documented machine-readable API/library;
- reliability;
- licensing compatible with ORAS use;
- predictable cost;
- time-zone correctness;
- freshness;
- astronomy coverage;
- testability.


## Weather provider decision

M0 does **not** select a specific weather API/vendor.

M0 freezes the required weather capability contract. The selected M6 implementation must provide, at minimum:
- cloud cover;
- precipitation probability/type;
- temperature;
- wind speed and gusts;
- humidity when useful;
- visibility;
- forecast issue/freshness time;
- forecast valid time;
- astronomy-specific seeing/transparency when a trustworthy source is available.

### Forecast behavior

ORAS AI must:
- distinguish current conditions from forecast conditions;
- preserve forecast valid time and freshness metadata;
- communicate uncertainty when forecasts are materially uncertain;
- avoid presenting long-range observing forecasts as guarantees;
- prefer astronomy-relevant metrics when they are qualified and reliable.

### Selection criteria

- forecast accuracy/quality for the ORAS observing site;
- reliability and uptime;
- predictable cost;
- nonprofit/commercial licensing compatibility;
- machine-readable interface stability;
- freshness/forecast horizon;
- astronomy relevance;
- time-zone correctness;
- testability.

The implementation remains behind the normalized Weather connector so later provider changes do not require rewriting orchestration logic.
