# Known limitations (release 1.1.0)

What this release does not do, does only partly, or has not been tested against the real world yet. Each item says what to do about it. Go-live decisions the owner must make are listed separately in [release-readiness.md](release-readiness.md).

## Not yet tested against the real world

| Item | Why | What to do |
|---|---|---|
| eSewa and Khalti were never called for real | The build machine's network blocks the providers' sandbox hosts. Both gateways are tested against the providers' documented requests and responses, including forged, replayed and wrong-amount cases. | Run one sandbox payment per provider (Khalti needs a test key from test-admin.khalti.com), then a small real payment with production keys before switching each on. See [deployment.md](deployment.md#11-payment-providers). |
| Email delivery | Mail was only written to logs or caught locally. | Configure the mail provider, then trigger a password reset and a test order before launch. |
| In-app browsers on real phones | Clicks from Telegram, Viber and similar in-app browsers are now counted ([BotDetector](../app/Domain/Referrals/BotDetector.php)), based on their published user agents. | Tap a referral link inside WhatsApp, Viber, Telegram and Facebook Messenger on a phone and check the click appears. |
| Production server and HTTPS | No production server exists yet. The Docker and Linux setups were tested on a build machine. | Follow [deployment.md](deployment.md); check `/up`, sign-in and the security headers on the real domain. |

## Missing features

The complete list of specification items that are partial or not built, with evidence, is in [feature-checklist.md](feature-checklist.md). The main ones are settings and staff-management screens, multi-factor sign-in for staff, and automated payouts. Campaigns, marketing materials, fraud flags, affiliate order and commission notices, and the public programme page were added in 1.1.0. The table below covers the ones staff will notice day to day.

| Feature | Impact | Workaround |
|---|---|---|
| Staff management screen | Staff accounts can only be created, disabled and re-enabled on the server, and roles cannot be changed. The `admins.manage` permission exists but no screen uses it. | `affiliate:create-staff`, `affiliate:create-admin --additional` and `affiliate:set-user-status` ([operations.md](operations.md#staff-accounts)). To change a role, disable the account and create a new one. |
| Multi-factor sign-in for staff | Optional in the specification. A stolen staff password is enough to sign in. | Long unique passwords, few Super Administrators, and disable leavers promptly. |
| Payout through a provider API | Finance sends each payout in the bank, eSewa or Khalti portal and records the reference. | This is the documented manual process ([ADR 0009](adr/0009-payouts.md)). |
| Tiered, recurring and multi-level commission | Optional in the specification; not built. Multi-level is off by design. | Use plans and per-affiliate rules. |
| Mobile app (Flutter) | Optional; not built. The API supports bearer tokens for a future app. | The website works on phones. |
| Category edit and delete | Categories can only be added from the screen. | Add a new category and move products to it. |

## Campaigns, marketing materials and fraud flags (1.1.0)

| Behaviour | What it means | What to do |
|---|---|---|
| A campaign budget does not stop commission | The budget is for tracking only. The Campaigns page warns when commission goes over it, but orders keep earning the campaign rate. | Watch the figures and end the campaign (untick **Active** or set a last day) when the budget is used. |
| A campaign is recognised only through a campaign link or coupon | An order joins a campaign only through the affiliate coupon, the clicked tracking link or a store-wide coupon linked to it. The affiliate's main link and a referral code typed at checkout never bring a campaign, even for a product in a running campaign. | Tell affiliates to make a link for the campaign on their **Referral links** page, or give them a campaign coupon. |
| Switching a campaign off affects unpaid orders | Commission is worked out at payment. An order placed during the campaign but paid after it was switched off earns the normal rates. Letting a campaign reach its last day does not do this. | End campaigns by date where you can. |
| Campaign rates are not in rate previews | `GET /admin/commission-rules/preview` and the affiliate **My rates** tab ignore campaign rules. Affiliates see campaign rates in the campaign box on **Referral links**. | Check the campaign's **Commission** line on the Campaigns page. |
| Marketing files cannot be replaced | A material's file cannot be changed after upload. | Add a new material with the new file and delete the old one. Its download count starts again. |
| Marketing files are not in the database backup | Files live in `storage/app/private/marketing`, not in MySQL. | Back up that folder too ([backup-and-restore.md](backup-and-restore.md)). |
| Fraud flags are signals, not decisions | A flag never holds, voids or blocks anything. Commission keeps maturing and payouts can still be approved while a flag is open. | Review open flags before approving payouts. Use hold, void and reject as usual. |
| Fraud thresholds are first guesses | The limits (for example 30% conversion, NPR 50,000 payouts) were not tuned on real traffic. | Watch how many flags turn out fine in the first months and change the `AFFILIATE_FRAUD_*` settings. |
| Not every fraud pattern is checked | Invalid referral attempts, repeated failed sign-ins and coupon abuse do not raise flags. They are handled by rate limits, the per-account sign-in limit and coupon limits. | Look at the audit log and the fraud review report when something looks wrong. |
| Fraud alerts go only to active staff with the right permission | If no active user has `fraud.review` (or `payouts.review` for payout flags), the flag waits in the queue unseen. | Keep at least one Affiliate Manager or Super Administrator active. |

## Only in the API, not on screens

Each of these works and is permission-checked, but staff need a developer or an API tool to use it:

- Preview of which commission rule applies (`GET /admin/commission-rules/preview`).
- Renaming a commission plan (`PATCH /admin/commission-plans/{id}`).
- The coupon `earns_commission` switch (coupons earn commission by default).
- The full list of online payment attempts (`GET /admin/payment-attempts?filter=all`); the screen shows only those needing attention.
- Filtering the audit log by action, person or date.

The "separate payer" rule for payouts is an `.env` setting (`AFFILIATE_PAYOUT_REQUIRE_SEPARATE_PAYER`), not a screen.

## Behaviour to be aware of

- **Closed members' balances.** When a member closes their membership, or staff deactivate it, the member loses the affiliate role. Any available balance cannot then be requested or paid out, because payouts require an active affiliate. The owner must decide the policy: pay out before closing, or allow a final payout. See [release-readiness.md](release-readiness.md#decisions-for-the-owner).
- **New terms are not enforced.** When a new terms version is published, members are asked to accept it, but they keep earning until they do ([ADR 0006](adr/0006-affiliate-membership-policy.md)).
- **Delivery steps and cancelling need `orders.manage`**, which only Super Administrators have. Finance and Support cannot mark orders shipped. Change the role matrix in `app/Support/Authorization/PermissionMatrix.php` if Support should do this.
- **Disputes** hold pending and approved commission only. Available commission on a disputed order is taken back only if the dispute is lost ([ADR 0011](adr/0011-payments-disputes-fulfilment.md)).
- **Adjustments**: the person who proposed an adjustment cannot approve it, but can reject (withdraw) it.
- **Registration says when an email is already registered.** Sign-in and password reset do not reveal this ([security review](security-review.md)).
- **No tax is withheld from commission**, and VAT treatment of the commission base is undecided ([ADR 0008](adr/0008-commission-accounting.md), [ADR 0009](adr/0009-payouts.md)).
- **Address line** is saved by the API for affiliates but has no field on the details form.
- **Support role** sees the Reports menu, which holds only the affiliate registrations report for that role.
- **Commission rates**: production starts with no commission rules, so no commission is earned until a Super Administrator creates them.

## Scaling limits

Comfortable at the launch target of about 100 affiliates. At ten times that (tested with 1,000 affiliates, 500k clicks and 50k orders), the one-year staff dashboard takes about 5 seconds and full-ledger sums under 1 second. Each has a noted fix in [load-and-concurrency.md](load-and-concurrency.md).

## Repository housekeeping

- `.github/workflows/issues.yml`, `pull-requests.yml` and `update-changelog.yml` came with the Laravel starter project. They call Laravel's own shared workflows, and `pull-requests.yml` runs with write permission on every new pull request. They do nothing useful here and should be deleted. `tests.yml` is also from the starter project; it runs the tests on PHP 8.3 and 8.4 and can stay.
