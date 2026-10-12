# Release-readiness assessment (1.0.0, updated for 1.2.0)

Date: 2026-10-12. Based on the [feature checklist](feature-checklist.md) (325 requirements checked against the code), the [Phase 9 report](phase-9-report.md) test results, the [security review](security-review.md) and the [known limitations](known-limitations.md). Updated for 1.1.0, which adds campaigns, marketing materials, fraud flags, affiliate notices and the public programme page ([Phase 10 report](phase-10-report.md)), and for 1.2.0, which adds the staff and settings screens, profile and password editing, product photos and category editing ([Phase 11 report](phase-11-report.md)).

## Verdict

**Ready for a controlled launch of the core programme, once the owner's items below are done. Not yet complete against the full specification.**

- **Ready.** The money path is built, tested and hardened:
  - the store, referral tracking and attribution
  - eSewa and Khalti confirmation by status API
  - commissions, the ledger, holds and reconciliation
  - payouts, dashboards, reports and audit
- **Evidence for that:**
  - 357 automated tests pass on SQLite and MySQL 8, and the 6 concurrency tests pass on MySQL (1.2.0; details in the [Phase 11 report](phase-11-report.md)).
  - Real parallel-request tests found one race, which is fixed.
  - A load test at ten times the launch size passed.
  - A restore drill passed.
  - A clean install and a Docker deployment were verified.
- **Missing from the spec.** Of 325 requirements, 280 are done, 25 partial, 4 not built, 10 optional and not built, and 6 need outside setup. The largest gaps are campaign landing pages and budget limits, a few notifications (placed orders, payout processing, new sign-ins), and staff multi-factor sign-in (optional).
- **Workarounds.** None of the gaps puts money or data at risk. Each has a workaround for a first launch of about 100 affiliates:
  - campaign links can open a product page, and staff end a campaign when its budget is used
  - fraud flags are signals: staff review them, and every payout, before paying

## Must be done before launch (owner)

| # | Item | Why it blocks |
|---|---|---|
| 1 | Merge the Phase 11 branch `phase-11-screens` (release 1.2.0); PR #8 (1.0.0) and PR #9 (1.1.0) are merged | Release code and docs |
| 2 | Production server, domain, HTTPS, mail provider | Nothing runs without them |
| 3 | `APP_KEY` generated on the server and stored in a password manager | Payout details are unreadable without it |
| 4 | One sandbox payment each for eSewa and Khalti, then one small real payment with production keys | The provider integrations have never been called for real |
| 5 | Commission rules created | Production has none, so nobody earns |
| 6 | Affiliate terms published, including prohibited promotion and spam rules | Members accept them on applying |
| 7 | Backups scheduled (database, `storage/app/private/marketing` and `storage/app/private/products`), copied off the server, one restore drill | Recovery |
| 8 | First Super Administrator created with `affiliate:create-admin`, then the other staff invited from the **Staff** screen | Finance and affiliate managers need their own logins |

Steps for each are in the owner checklist (project files: `affiliate-system/owner-checklist.md`) and [deployment.md](deployment.md).

## Decisions for the owner

Each has a working default. The ones marked * can be changed on the **Settings** screen at any time.

| Decision | Default now |
|---|---|
| VAT excluded from the commission base? | Commission on the VAT-inclusive price |
| TDS withheld from payouts? | Nothing withheld |
| Holding period and release * | 14 days, then automatic |
| Payout minimum * | NPR 1,000 |
| Separate approver and payer * | Off |
| Final payout for a closed member's balance | Not possible after closing |
| Who can mark orders shipped | Super Administrator only |
| Hide "email already registered" at sign-up | Shown |
| Cookie consent banner | None (referral cookie is first-party; legal review advised) |
| Staff multi-factor sign-in before launch | Not built (recommended next) |
| Fraud flag thresholds * | 30% conversion (5+ orders), 1,000 clicks with no order, 30% refunds (5+ orders), 5 times normal weekly earnings (NPR 10,000+), payouts from NPR 50,000, payouts within 7 days of a detail change |

Legal and tax points for qualified review in Nepal are listed in the [Phase 0 plan](phase-0-plan.md#nepal-legal-and-tax-notes-for-qualified-local-review-not-legal-advice). No legal or tax compliance is claimed.

## Gaps against the specification

The full list, with evidence, is in [feature-checklist.md](feature-checklist.md). In order of value:

1. **Campaign extras** (§7, §8): campaign landing pages, a budget that stops commission, and per-campaign attribution rules (optional).
2. **Remaining notifications** (§19): a notice when a referred order is placed but not yet paid, a payout "processing" step, and new sign-ins.
3. **Remaining fraud signals** (§15): invalid referral attempts and repeated failed sign-ins are rate-limited but raise no flag.
4. **Integrations** (§5, §16): payment and mail settings stay in `.env`, and the four staff roles' permissions are fixed in code (staff are moved between roles on screen).
5. Smaller items:
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
| Fraud | Low to medium | Basic protections (self-referral, bots, duplicates, limits) plus six fraud signals with a review queue. Flags never block anything, and the thresholds are untuned first guesses, so staff must still review flags and payouts before paying. |
| Operations | Low | Documented and tested install, Docker stack, backups, restore drill, runbook |
| Scale | Low at launch size | Comfortable at 100 affiliates; known limits at 10× noted |
