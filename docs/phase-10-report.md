# Phase 10 report: Closing the specification gaps

Date: 2026-10-12 · Branch `phase-10-gaps` · Status: **complete; ready to merge as release 1.1.0**

Phase 10 adds five features on top of release 1.0.0 (PR #8). They close the largest gaps listed in the 1.0.0 [release-readiness assessment](release-readiness.md). The work is release 1.1.0 in the [changelog](../CHANGELOG.md).

## Spec items

| Spec item | Result | Where |
|---|---|---|
| Notifications for affiliates (§19) | An email and in-app notice when a referred order is paid, with the masked order number (last 4 characters) and the commission and the date it stops being pending. It says when no commission applies, or when it is on hold because the membership is not active. A notice when commission is reversed by a refund or cancelled by staff; the staff reason is never included. | `app/Notifications/Affiliate/ReferredOrderPaid.php`, `CommissionReversed.php`, sent from `CommissionService` and `CommissionLifecycle::void`; `tests/Feature/Commissions/AffiliateNoticesTest.php` |
| Security notice (§19) | A "Your password was changed" email after a password reset. | `app/Notifications/Auth/PasswordChanged.php`, `PasswordResetController`; `PasswordResetTest` |
| Fraud signals (§15) | Six signals: unusually high conversion, many clicks with no orders, high refund rate, sudden jump in earnings (nightly), large payout request and payout soon after changing payout details (on each request). Thresholds in `config/affiliate.php` under `fraud`, set with `AFFILIATE_FRAUD_*` (see `.env.example`). Each flag carries what was measured and guidance for the reviewer. | `app/Domain/Fraud/FraudDetector.php`, `app/Enums/FraudSignal.php`, `affiliate:detect-fraud` (daily 04:00 Nepal time, `routes/console.php`), `PayoutService::request`; `tests/Feature/Fraud/FraudDetectionTest.php` |
| Fraud review queue (§15) | `/admin/fraud` (**Fraud review**): open, confirmed and dismissed flags; confirm or dismiss with a note. Reviewers are emailed (`FraudFlagsRaised`); payout flags also go to payout reviewers. Open flags show on the payout screen. API: `GET /admin/fraud-flags`, `POST /admin/fraud-flags/{flag}/review` (`fraud.review`). Audited as `fraud_flag.raised` and `fraud_flag.reviewed`. | `FraudFlagController`, `resources/js/pages/admin/FraudPage.tsx`, `PayoutsPage.tsx`; migration `2026_10_16_100000_create_fraud_flags_table` |
| Public affiliate landing page (§21) | `/affiliates`, linked as **Earn with us** in the store menu: how it works, the standard rate, attribution window, holding period, payout minimum and methods, who can join, FAQ and the current terms. The figures come from the live configuration and the global rule in force. | `GET /store/affiliate-programme` (`AffiliateProgrammeController`), `resources/js/pages/store/AffiliateProgrammePage.tsx`; `tests/Feature/Store/AffiliateProgrammePageTest.php` |
| Campaigns (§8, §9, §10) | Dated campaigns for chosen products and affiliates, with an informational budget and results (paid orders, sales, net commission). A commission rule with scope `campaign` sets the rate. Orders join a campaign through a campaign coupon or a campaign tracking link. Screens: **Campaigns**, a campaign scope on **Commission rules**, a campaign field on **Coupons**, and **Campaigns you can promote** on the affiliate **Referral links** page. Audited as `campaign.created` and `campaign.updated`. | Migration `2026_10_17_100000_create_campaign_tables`, `app/Models/Campaign.php`, `RuleResolver`, `CheckoutService::campaignFor`, admin and affiliate `CampaignController`, [ADR 0008](adr/0008-commission-accounting.md); `tests/Feature/Campaigns/CampaignTest.php` |
| Marketing materials (§8, §12) | Staff upload JPG, PNG, WebP or GIF images, MP4 videos, PDFs or ZIP files up to 20 MB, or text-only post text. Affiliates browse, download and copy them at **Marketing materials**. Audited as `marketing_asset.created`, `.updated` and `.deleted`. | Migration `2026_10_18_100000_create_marketing_assets_table`, `app/Domain/Marketing/MarketingAssetService.php`, admin and affiliate `MarketingAssetController`, `/admin/marketing`, `/account/affiliate/marketing`; `tests/Feature/Marketing/MarketingAssetTest.php` |
| Documentation | Administrator, affiliate and API guides, OpenAPI 1.1.0, architecture, database schema, migrations, known limitations, feature checklist, release readiness, requirements, changelog. Deployment and backup guides cover the upload limits and the marketing folder. | `docs/`, [CHANGELOG](../CHANGELOG.md) |

## Design decisions

- **Flags never act on their own.** A fraud flag does not hold, void or block anything. Reviewers use the existing hold, void, reject and suspend tools, and record a finding on the flag. Confirming a flag only records it. This keeps false positives from stopping honest affiliates' money while the thresholds are untuned.
- **No repeat flags.** A nightly signal is not raised again for the same affiliate while one is open or within 30 days of the last one, and a unique `dedupe_key` stops a repeated run from duplicating it. Payout signals are raised once per payout request. Payout checks run inside the request's transaction, and notices go out after commit.
- **Campaign precedence.** The campaign rate comes after product and category exclusions and before affiliate overrides, and only for the products the campaign covers. Other lines in the same order earn the normal rates.
- **How an order joins a campaign.** At checkout: the attributing affiliate coupon's campaign, else the clicked tracking link's, else a store-wide coupon's. It counts only while the campaign runs and the credited affiliate takes part. The main referral link and a typed referral code never bring a campaign, so a campaign can be traced to a specific link or code.
- **Switching a campaign off.** Commission is created at payment. If staff switch a campaign off before an order is paid, that order earns the normal rates.
- **Campaigns are never deleted.** Orders and rules point to them. Staff switch them off or end them. The budget is informational and never stops commission.
- **Affiliates only link to open campaigns.** `POST` and `PATCH /affiliate/links` accept a campaign only if it is running and open to the affiliate.
- **Upload safety.** The file type is sniffed from the content, never taken from the name or the browser. SVG is refused because it can carry script. Files are stored on the private disk (`storage/app/private/marketing`) under generated names and served only through the API, with `nosniff` and a `default-src 'none'; sandbox` policy. Only images can be shown inline. A file cannot be replaced; staff upload a new material.
- **Campaign materials follow the campaign.** A material linked to a campaign is shown only while the campaign runs and only to affiliates taking part. Affiliate downloads are counted; previews and staff downloads are not.
- **Security headers.** The site-wide policy now allows `blob:` images for marketing previews fetched with the session. A response that sets its own Content-Security-Policy keeps it.
- **Privacy in notices.** Affiliate notices carry only the masked order number, never customer details. The staff reason for a void is never sent.
- **The public page shows only the global rate.** Plan, affiliate, product and campaign rates are not published.

## Verification

Results to be filled in by the lead.

| Check | Result |
|---|---|
| `php artisan test` on SQLite | **339 passed** (2,332 assertions) |
| `php artisan test` on MySQL 8.0.46 | **339 passed** |
| `php artisan test tests/Concurrency` on MySQL | **6 passed** |
| Migrations up, down, up on MySQL 8 | pass (the three new migrations rolled back and re-applied) |
| `vendor/bin/pint --test` | pass |
| `php artisan affiliate:permissions-matrix --check` | pass |
| `npm run lint`, `npm run typecheck`, `npm run build` | pass (main bundle 498 kB, 140 kB gzipped) |
| `composer audit`, `npm audit --omit=dev` | no advisories |
| OpenAPI lint (Redocly) | valid (style warnings only, as in 1.0.0) |

## Not done here, and why

- **No settings or staff screens.** Fraud thresholds and the upload limit are `.env` settings, like the other programme settings.
- **No campaign landing pages or budget limits.** A campaign link can open a product page. See [known-limitations.md](known-limitations.md).
- **Thresholds are not tuned.** They are first guesses until there is real traffic.
