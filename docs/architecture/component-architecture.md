# Component Architecture

## Components

### Request Gateway
Receives authenticated chat requests; enforces CSRF/session checks, request size, authorization, and basic abuse controls.

### Membership Authorizer
Determines whether the current WordPress user may use ORAS AI.

### Domain Guard
Classifies requests as ORAS, astronomy, allowed crossover, off-topic, or ambiguous. Obvious off-topic requests stop here.

### Orchestrator
Chooses retrieval, connectors, tools, and answer strategy.

### Knowledge Registry
Tracks sources, hashes, visibility, classification, provenance, lifecycle, and review state.

### Ingestion Pipeline
Discovers content, applies deterministic rules, invokes AI only where judgment is needed, extracts mixed stable content, and retires stale managed entries.

### Retrieval Service
Searches approved knowledge under server-side visibility filters and returns bounded evidence with provenance.

### Live Connector Layer
Adapters for WooCommerce, Events Calendar, PMPro, Fluent Support, astronomy, weather, and future ORAS systems.

### Response Composer
Builds evidence-aware answers, source links, and validated action links.

### Fluent Support Bridge
Creates confirmed support/feedback tickets and maps ORAS topics to configured Fluent Support routing.

### Usage Ledger
Tracks per-member requests, token/provider usage when available, rate-limit state, and cost.

### Audit Facility
Records security/administrative events without full-prompt logging by default.

## Dependency rule

Provider/vendor-specific code is isolated behind adapters. Business policy, source precedence, and authorization do not depend on vendor response formats.

## Failure isolation

Failure of weather must not break stable ORAS FAQ answers. Failure of Fluent Support must not prevent normal answers. Connector failures affect only workflows that need that connector.
