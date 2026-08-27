# Paid Memberships Pro Integration

## Role

PMPro provides membership state for:
- AI access eligibility;
- member-specific membership questions;
- optional future feature tiers.

## Initial eligibility rule

- Any authenticated user with an **active ORAS membership at any membership level** may use ORAS AI.
- WordPress administrators may use ORAS AI for administration/testing even without an active membership.
- Logged-in users without an active ORAS membership are denied production member-chat access.
- Anonymous visitors are denied production AI access.

## Authorization

Backend logic resolves current membership from authoritative WordPress/PMPro state or a short-lived server cache. Browser claims are not authoritative.

## Data minimization

The model receives only request-relevant facts, such as active/inactive status or a necessary membership tier. Unrelated billing/profile data is excluded.

## Visibility

Member-only knowledge is filtered server-side before retrieval. The model never decides whether a source may be shown.
