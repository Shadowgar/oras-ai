# Requirements Traceability Matrix

| Requirement group | Governing ADRs | First milestone | Test family |
|---|---|---|---|
| AUTH-* | ADR-0003, ADR-0013 | M3 | AT-AUTH |
| DOMAIN-* | ADR-0004, ADR-0005 | M3 | AT-DOMAIN |
| KB-* | ADR-0006, ADR-0007, ADR-0008 | M2 | AT-KB |
| RET-* | ADR-0009, ADR-0013 | M3 | AT-RET |
| LIVE-* | ADR-0010 | M5 | AT-LIVE |
| ASTRO-* | ADR-0011, ADR-0012 | M6 | AT-ASTRO |
| SUP-* | ADR-0014 | M7 | AT-SUPPORT |
| ACT-* | ADR-0015 | M8 | AT-ACTION |
| COST-* | ADR-0016 | M3 | AT-COST |
| UX-001–UX-003, UX-005–UX-006 | ADR-0003, ADR-0004 | M4 | AT-UX |
| UX-004 | ADR-0014 | M7 | AT-UX, AT-SUPPORT |
| ADM-001–ADM-004, ADM-006 | ADR-0006, ADR-0008 | M2 | AT-ADMIN |
| ADM-005 (scanner model selection slice) | ADR-0002 | M2 | AT-ADMIN |
| ADM-005 (quota administration slice) | ADR-0016 | M3 | AT-COST, AT-ADMIN |
| ADM-005 (live connector administration slice) | ADR-0010, ADR-0012 | M5–M6 | AT-LIVE, AT-ASTRO, AT-ADMIN |
| ADM-005 (support routing administration slice) | ADR-0014 | M7 | AT-SUPPORT, AT-ADMIN |
| NFR-SEC-* | ADR-0013, ADR-0017 | M3 | AT-SEC |
| NFR-PRIV-001–NFR-PRIV-002, NFR-PRIV-004 | ADR-0017 | M4 | AT-PRIV |
| NFR-PRIV-003 | ADR-0014, ADR-0017 | M7 | AT-PRIV, AT-SUPPORT |
| NFR-A11Y-* | ADR-0018 | M4 | AT-A11Y |
| NFR-MNT-* | ADR-0001, ADR-0002 | M1 | AT-ARCH |
| NFR-REL-004 | ADR-0009 | M2 active-artifact eligibility foundation; M3 retrieval enforcement | AT-KB, AT-RET |
| NFR-REL-005 | ADR-0013, ADR-0016 | M3 | AT-SEC, AT-COST |
| NFR-PERF-003 | ADR-0009, ADR-0016 | M3 | AT-RET, AT-COST |
| NFR-OBS-001, NFR-OBS-004 | ADR-0019 | M2 | AT-OBS |
| NFR-OBS-003 | ADR-0019 | M3 | AT-OBS, AT-DOMAIN, AT-COST |
| NFR-OBS-002 | ADR-0019 | M5 | AT-OBS, AT-LIVE |

A milestone cannot be accepted when an RB requirement first required there lacks implementation, verification, and retained/reproducible evidence.

For PMPro scope, M3 `AUTH-002` uses only the Boolean answer to whether the authenticated user has any active membership. Member-specific PMPro answer context remains `LIVE-003` work first required in M5.

For staged UX scope, M4 qualifies trusted source-link presentation. `UX-004` escalation preview and `NFR-PRIV-003` ticket-context minimization are to be implemented and qualified with Fluent Support in M7; member actions remain under `ACT-*` in M8.
