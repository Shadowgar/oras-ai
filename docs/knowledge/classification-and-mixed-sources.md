# Classification and Mixed-Source Extraction

## Outcomes

### Static Knowledge
Durable information useful for future answers.

### Live Data
Information whose authoritative value must be fetched at request time.

### Mixed
A source containing both durable and changing information.

### Ignore
Template, utility, account, checkout, test, empty, irrelevant, or otherwise unsuitable content.

### Historical Event Knowledge
Past event pages/schedules with archival value. Searchable only for historical/past-event intent and never authoritative for current dates, pricing, deadlines, or availability.

### Needs Review
Content the system cannot safely classify/extract automatically.

## Deterministic rules

Minimum rule set:

- `tribe_events` → Live Data
- WooCommerce `product` → Live Data
- Elementor template/library records → Ignore
- MailPoet utility records → Ignore
- menu/template helper records → Ignore
- known account/checkout/confirmation/profile utility pages → Ignore
- ORAS speaker biographies → synchronized Event/educational knowledge unless explicitly excluded; speaker records alone are not authoritative for current Board/organizational roles

Rules are versioned and tested.

## Mixed-source extraction

For mixed pages the classifier returns structured fields:

- stable title;
- stable content;
- excluded dynamic claims;
- dynamic fact types;
- category;
- visibility;
- confidence;
- extraction reason.

Example:

```json
{
  "classification": "mixed",
  "stable_title": "About AstroBlast",
  "stable_content": "AstroBlast is ORAS's annual astronomy gathering...",
  "dynamic_fact_types": [
    "event_date",
    "registration_deadline",
    "ticket_price",
    "availability"
  ]
}
```

The stable fragment becomes knowledge. Dynamic fields do not.

## Guardrail

Changing data must not be disguised as timeless knowledge. Example: changing `Tickets are $25` to `Tickets typically cost $25` is not a valid stable extraction.

## Review threshold

Route to review when:
- stable/dynamic content cannot be separated reliably;
- policy/effective-date ambiguity exists;
- extraction would omit critical qualifications;
- confidence is low;
- validation detects dynamic claims in the stable fragment.
