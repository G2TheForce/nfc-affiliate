# Release-readiness assessment (1.0.0)

Date: 2026-10-12. Based on the [feature checklist](feature-checklist.md) (325 requirements checked against the code), the [Phase 9 report](phase-9-report.md) test results, the [security review](security-review.md) and the [known limitations](known-limitations.md).

## Verdict

**Ready for a controlled launch of the core programme, once the owner's items below are done. Not yet complete against the full specification.**

- **Ready.** The money path is built, tested and hardened:
  - the store, referral tracking and attribution
  - eSewa and Khalti confirmation by status API
  - commissions, the ledger, holds and reconciliation
  - payouts, dashboards, reports and audit
- **Evidence for that:**
  - 313 automated tests pass on SQLite and MySQL 8.
  - Real parallel-request tests found one race, which is fixed.
  - A load test at ten times the launch size passed.
  - A restore drill passed.
  - A clean install and a Docker deployment were verified.
- **Missing from the spec.** Of 325 requirements, 253 are done, 32 partial, 24 not built, 10 optional and not built, and 6 need outside setup. The largest gaps are campaigns, a marketing assets library, fraud detection with flags and a review queue, a public affiliate landing page, settings and staff-management screens, and some notifications.
- **Workarounds.** None of the gaps puts money or data at risk. Each has a workaround for a first launch of about 100 affiliates:
  - coupons stand in for campaigns
  - assets are shared outside the app
  - fraud is checked by staff review before payouts
  - the programme is explained when someone signs up

## Must be done before launch (owner)

| # | Item | Why it blocks |
|---|---|---|
| 1 | Merge PR #8 | Release code and docs |
| 2 | Production server, domain, HTTPS, mail provider | Nothing runs without them |
| 3 | `APP_KEY` generated on the server and stored in a password manager | Payout details are unreadable without it |
| 4 | One sandbox payment each for eSewa and Khalti, then one small real payment with production keys | The provider integrations have never been called for real |
| 5 | Commission rules created | Production has none, so nobody earns |
| 6 | Affiliate terms published, including prohibited promotion and spam rules | Members accept them on applying |
| 7 | Backups scheduled, copied off the server, one restore drill | Recovery |
| 8 | Staff accounts created with `affiliate:create-staff` | Finance and affiliate managers need their own logins |

Steps for each are in the owner checklist (project files: `affiliate-system/owner-checklist.md`) and [deployment.md](deployment.md).

## Decisions for the owner

Each has a working default.

| Decision | Default now |
|---|---|
| VAT excluded from the commission base? | Commission on the VAT-inclusive price |
| TDS withheld from payouts? | Nothing withheld |
| Holding period and release | 14 days, then automatic |
| Payout minimum | NPR 1,000 |
| Separate approver and payer | Off |
| Final payout for a closed member's balance | Not possible after closing |
| Who can mark orders shipped | Super Administrator only |
| Hide "email already registered" at sign-up | Shown |
| Cookie consent banner | None (referral cookie is first-party; legal review advised) |
| Staff multi-factor sign-in before launch | Not built (recommended next) |

Legal and tax points for qualified review in Nepal are listed in the [Phase 0 plan](phase-0-plan.md#nepal-legal-and-tax-notes-for-qualified-local-review-not-legal-advice). No legal or tax compliance is claimed.

## Gaps against the specification

The full list, with evidence, is in [feature-checklist.md](feature-checklist.md). In order of value:

1. **Fraud signals and review queue** (spec §15): flags for unusual click-to-order ratios, rapid earnings, refund patterns, abnormal payout requests and payout-detail changes; configurable thresholds; a review queue.
2. **Missing notifications** (§19):
   - for affiliates: new attributed order, commission earned, commission reversed;
   - security: password changed.
3. **Public affiliate landing page** (§21): how it works, eligibility, terms, payouts, FAQ.
4. **Campaigns** (§8, §9): dated campaigns tied to products and affiliates, with commission overrides.
5. **Marketing assets** (§8, §12): upload by staff, download by affiliates.
6. **Settings and staff screens** (§5, §16): change programme settings and manage staff without the server.
7. Smaller items:
   - customers editing their profile
   - product images
   - category editing
   - a separate "processing" payout state
   - personal-data deletion for closed accounts
   - an accessibility audit

Optional and not built: multi-factor sign-in, tiered, recurring and multi-level commission, a Flutter app, SMS or WhatsApp notices, and extra funnel events.

## Risk summary

| Area | Risk | Basis |
|---|---|---|
| Money correctness | Low | Integer paisa, append-only ledger, idempotent and locked money operations, nightly reconciliation, concurrency tests |
| Payment confirmation | Medium until the sandbox and live checks are done | Code follows the providers' documented protocols and refuses redirects as proof; never run against the real services |
| Security | Low to medium | Internal review with fixes and tests; no external penetration test; no staff MFA |
| Fraud | Medium | Basic protections only (self-referral, bots, duplicates, limits); relies on staff review before paying |
| Operations | Low | Documented and tested install, Docker stack, backups, restore drill, runbook |
| Scale | Low at launch size | Comfortable at 100 affiliates; known limits at 10× noted |
