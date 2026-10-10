# Phase 2 report: Affiliate onboarding

Date: 2026-10-10 · Status: **complete, verified in a test environment**

## What was built

| Item | Where |
|---|---|
| Affiliate profiles, append-only status history, terms versions and acceptances, staff notes, in-app notifications table | `database/migrations/2026_10_10_*` |
| Lifecycle (pending, approved, rejected, suspended, deactivated) with allowed transitions | `App\Enums\AffiliateStatus`, [ADR 0006](adr/0006-affiliate-membership-policy.md) |
| One service for every membership change: locked, transactional, audited, notifies after commit | `App\Domain\Affiliates\AffiliateMembershipService` |
| Random, permanent 8-character referral codes and unique slugs | `ReferralIdentifierGenerator` |
| Possible-duplicate flags (same phone or website host) for reviewers | `DuplicateSignals` |
| Member API: current terms, apply or re-apply, own profile, edit, accept new terms, close membership | `/api/v1/affiliate/*` |
| Staff API: list with filters and counts, detail, approve, reject, suspend, reinstate, deactivate, reactivate, notes, publish terms | `/api/v1/admin/affiliates/*`, `/api/v1/admin/terms` |
| Notifications: application received (applicant), new application (reviewers, in-app), every status change (member, email and in-app) | `app/Notifications/Affiliate` |
| In-app notifications list, mark as read, unread badge | `/api/v1/notifications`, header |
| Web screens: apply form with terms, status, referral code, profile edit, terms re-acceptance, close membership, history; staff list with status tabs and search, detail with action dialogs and notes, terms publishing | `resources/js/pages` |

## Verification (actual results)

| Check | Result |
|---|---|
| `php artisan test` on SQLite | 91 passed |
| `php artisan test` on MySQL 8.0.46 | 91 passed |
| `npm run typecheck`, `npm run lint`, `npm run build` | pass |
| `vendor/bin/pint --test` | pass |
| OpenAPI (Redocly lint) | valid |
| Browser test (Chromium, MySQL, real queue worker) | passed (below) |

The browser test covered:
1. The admin publishes terms.
2. A new customer registers and verifies their email.
3. Submitting without ticking the terms box shows the error.
4. The customer applies and sees "Pending review".
5. The admin sees the application and the notification badge.
6. The admin approves with an internal note.
7. The member sees their referral code and the approval notification.
8. The internal note does not appear on the member's page or in the approval email.

### New security and rule tests

- The permission matrix for every staff affiliate endpoint, across all six roles. Finance and support can view but cannot approve or suspend. Only a super admin can publish terms.
- An affiliate cannot approve their own application through the admin API.
- Staff cannot apply, and cannot act on a profile that belongs to them.
- Unverified users cannot apply. A second application is refused.
- Applicants cannot set status, referral code or approval date (mass assignment).
- The member must accept the exact current terms version. Old versions are refused.
- Re-applying after a rejection works only after the cooldown.
- Invalid transitions are refused. Approving twice records one transition.
- Status history and published terms cannot be edited.
- Internal notes never reach the member: checked on the profile API, in-app notifications and email.
- Member endpoints ignore any profile id sent by the client.
- Notifications are private. Another user's notification id returns 404.
- Suspended members cannot close their membership to escape a review.

## Not done yet / known limitations

- Referral links, the `/ref/{code}` redirect, click tracking and attribution are Phase 3. The member page says so instead of showing a link that doesn't work yet.
- Concurrency is protected by row locks and state re-checks. A test with two processes running at the same time on MySQL is planned with the payout work (Phase 5).
- The affiliate programme public landing page and real terms text need business and legal input. The development seeder publishes a clearly marked placeholder.
- Whether unaccepted new terms should block earning is a business decision, noted in ADR 0006.
