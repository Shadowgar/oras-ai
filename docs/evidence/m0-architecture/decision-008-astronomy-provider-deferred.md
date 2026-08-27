# M0 Decision 008 — Astronomy Provider Selection Deferred to M6

**Status:** Resolved during M0 review

## Decision

The architecture does **not** select a specific astronomy API, library, or vendor during M0.

M0 instead freezes the required astronomy capability contract and the normalized connector boundary.

The actual astronomy provider/library is selected during **M6 — Astronomy and Weather Intelligence** after qualification.

## Required capability contract

The selected M6 implementation must support or deterministically calculate, at minimum:
- current celestial positions for the ORAS observatory site;
- sunset and astronomical twilight;
- Moon phase, rise/set, and altitude;
- planet visibility;
- target altitude/azimuth or rise/transit/set as needed;
- correct location/time-zone handling;
- reproducible test fixtures.

## Selection criteria

- astronomical accuracy;
- reliability;
- predictable cost;
- licensing compatible with ORAS use;
- stable machine-readable output;
- time-zone correctness;
- testability;
- maintainability.

The provider remains behind ORAS AI's normalized Astronomy connector so a later provider change does not require rewriting answer-orchestration logic.

This remains part of the Draft M0 package until the complete M0 architecture is owner-approved and frozen.
