# Scanner Runbook

## Normal scan

Expected:
1. discover sources;
2. skip unchanged sources;
3. classify/extract new or changed sources;
4. update derived knowledge;
5. report errors.

## Rebuild classifications

Use after:
- deterministic rule change;
- classifier schema change;
- mixed-source extraction change;
- known prototype bug.

Before:
- confirm backup;
- record current counts;
- record rule/extraction/model version.

After:
- confirm zero unintended Pending sources;
- inspect Needs Review;
- inspect Static→Live and Static→Ignore changes;
- confirm derived records retired correctly;
- spot-check high-value ORAS sources.

## High-value checks

- Rules
- Observatory
- Registration Information
- AstroBlast overview
- Public Nights
- Event records
- Observer Pass products
- Membership Levels
- Equipment Exchange
- utility/template pages

## Safety

Scanner processing must not execute arbitrary code or make arbitrary network requests based on page content.
