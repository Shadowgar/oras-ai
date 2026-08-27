# Recommended Repository Layout

```text
oras-ai-assistant/
├── oras-ai-assistant.php
├── includes/
│   ├── Admin/
│   ├── Auth/
│   ├── Chat/
│   ├── Connectors/
│   │   ├── Astronomy/
│   │   ├── EventsCalendar/
│   │   ├── FluentSupport/
│   │   ├── OpenAI/
│   │   ├── PMPro/
│   │   ├── Weather/
│   │   └── WooCommerce/
│   ├── DomainGuard/
│   ├── Knowledge/
│   ├── Observability/
│   ├── Support/
│   └── Usage/
├── assets/
│   ├── css/
│   └── js/
├── tests/
│   ├── unit/
│   ├── integration/
│   ├── fixtures/
│   └── evals/
├── docs/
│   └── [this architecture package]
├── README.md
└── .gitignore
```

Exact PHP namespaces/classes are implementation decisions as long as architectural boundaries remain visible.

Accepted ADRs are versioned with code and are not silently rewritten.
