# Information Flow and Source Precedence

```mermaid
flowchart TD
    Q[Member question] --> AUTH{Authorized?}
    AUTH -- No --> DENY[Deny AI access]
    AUTH -- Yes --> RATE{Within limits?}
    RATE -- No --> LIMIT[Rate-limit response]
    RATE -- Yes --> DOMAIN{Allowed domain?}
    DOMAIN -- No --> OFF[Concise refusal]
    DOMAIN -- Yes --> PLAN[Intent/evidence plan]
    PLAN --> K[Approved ORAS knowledge]
    PLAN --> L[Live ORAS systems]
    PLAN --> A[Current astronomy]
    PLAN --> W[Weather]
    PLAN --> G[General astronomy knowledge]
    K --> C[Compose]
    L --> C
    A --> C
    W --> C
    G --> C
    C --> CONF{Sufficient authority?}
    CONF -- Yes --> ANS[Answer + sources/actions]
    CONF -- No --> ESC[Offer Fluent Support]
```

## Fact-level precedence

1. live ORAS structured state;
2. approved ORAS policy;
3. synchronized ORAS knowledge;
4. current astronomy/weather data;
5. general model astronomy knowledge;
6. no answer/escalation.

### Examples

If a static page and current event record disagree on AstroBlast date, the live event record wins.

If copied text and WooCommerce disagree on Observer Pass price, WooCommerce wins.

If a general model assumption conflicts with approved ORAS access rules, the ORAS rule wins.

If seasonal model knowledge says an object is "visible" but current ephemeris places it below the horizon, the calculation wins.
