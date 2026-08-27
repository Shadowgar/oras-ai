# Source-of-Truth Policy

## Purpose

ORAS AI must know which system is authoritative for each fact instead of allowing the model to choose among conflicting text.

## Authority table

| Fact type | Authority |
|---|---|
| ORAS policy/rules | Approved ORAS policy source |
| Event date/time | The Events Calendar authoritative event record |
| Event registration/purchase | WooCommerce/event integration |
| Product/pass price | WooCommerce |
| Product/pass availability | WooCommerce or authoritative inventory source |
| Member status | PMPro / WordPress member state |
| Support ticket status | Fluent Support |
| Facility description | Approved synchronized ORAS knowledge |
| Directions | Approved ORAS directions source |
| Current sky positions | Astronomy provider/calculation |
| Current Moon data | Astronomy provider/calculation |
| Current weather | Weather provider |
| General astronomy explanation | Model knowledge and/or approved references |

## Rules

Static knowledge may explain what a product, event, facility, or policy is. It must not be authoritative for a value that belongs to a live structured system.

Every synchronized artifact retains source hash, source modification time, sync time, and extraction version.

When source freshness cannot be established for a time-sensitive fact, the assistant must query live or state that it cannot confirm the current value.

Conflicts are not resolved by majority vote among retrieved chunks. The authoritative system for the fact wins. Repeated conflicts should create an admin quality signal.


## Privacy and security policy sources

Public ORAS privacy and website-security policy pages are eligible searchable knowledge under **Policies & Rules**.

They should be retrieved only when the member's question is materially related to:
- privacy;
- personal-data handling;
- website security;
- vulnerability reporting;
- legal/terms questions;
- related ORAS policy interpretation.

They should not be injected into ordinary astronomy, event, membership, or observatory questions merely because they are public policy pages.

When a policy page conflicts with a current approved ORAS policy source, the current approved source wins under the normal source-precedence rules.


## Historical event knowledge

Past event pages and schedules may remain searchable as **Historical Event Knowledge** when they provide useful archival context.

They may answer questions such as:
- who spoke at ORAS in a prior year;
- what activities were offered at a past AstroBlast;
- whether ORAS previously held a certain workshop;
- what a past schedule looked like.

Historical sources have **lower retrieval priority** than current event systems and current approved ORAS pages.

They must not answer current or upcoming event dates, current registration deadlines, current ticket/pass pricing, current availability, or current schedules. For current/upcoming-event intent, live event/product systems always win.
