# Phase 11 report: Staff, settings and profile screens

Date: 2026-10-12 · Branch `phase-11-screens` · Status: **complete; ready to merge as release 1.2.0**

Phase 11 adds four features on top of release 1.1.0 (PR #9). They close the gaps that most often needed the developer or the server: staff accounts, programme settings, editing your own details, and product photos and categories. The work is release 1.2.0 in the [changelog](../CHANGELOG.md).

## Spec items

| Spec item | Result | Where |
|---|---|---|
| Super Admin manages staff accounts (§5) | **Staff** screen (`/admin/staff`, `admins.manage`). Invite by name, email and role; the person gets an email with a link to set their password (`StaffInvitation`, a normal password-reset token). Resend the link until the person first signs in. Change a member's role in place. Disable or re-enable with a reason. A role change or a disable deletes the account's tokens and sessions. Nobody can change their own role or disable themselves, and the last active Super Administrator can be neither demoted nor disabled. The `affiliate:set-user-status` command now uses the same code. Audited as `admin.staff_created` (`via: screen`), `admin.staff_invitation_resent`, `admin.staff_role_changed` (`from`, `to`) and `user.status_changed`. | `app/Domain/Staff/StaffAccounts.php`, `app/Notifications/Auth/StaffInvitation.php`, `Admin\StaffController`, `resources/js/pages/admin/StaffPage.tsx`, `SetUserStatus`; `tests/Feature/Staff/StaffScreenTest.php` |
| Settings changeable by admins (§5, §14, §16) | **Settings** screen (`/admin/settings`, `settings.manage`) for 18 programme settings in five groups: Referrals, Commission, Payouts, Store and Fraud alerts. Each has a type and limits. Overrides are stored in `system_settings` and applied over `config('affiliate.*')` at boot, so `.env` values are now defaults. **Use default** removes an override. Audited as `setting.changed` and `setting.reset` with the old and new value. | Migration `2026_10_19_100000_create_system_settings_table`, `app/Domain/Settings/SettingsRegistry.php`, `SystemSettings.php`, `Admin\SettingsController`, `resources/js/pages/admin/SettingsPage.tsx`; `tests/Feature/Settings/SettingsScreenTest.php` |
| Customer and member account management (§5, §12) | **My profile** at `/account/profile` and `/admin/profile`: change name and phone (`PATCH /auth/me`), and change password with the current one (`POST /auth/password`). A password change keeps the current session, signs out every other session and token, and emails `PasswordChanged`. Email is not self-service. Audited as `user.profile_updated` (field names only) and `auth.password_changed`. | `Auth\ProfileController`, `resources/js/pages/shared/ProfilePage.tsx`; `tests/Feature/Auth/ProfileTest.php` |
| Product images (§8) | One photo per product: JPEG, PNG or WebP detected from the content, up to 5 MB and 6,000 pixels a side. Stored on the private disk under `storage/app/private/products` with a new name each time; the old file is deleted. Served publicly at `/media/products/{file}`. Products return `image_url`. Shown on the shop, the product page and the staff product list. Audited as `product.image_changed` and `product.image_removed`. | Migration `2026_10_20_100000_add_image_to_products`, `app/Domain/Store/ProductImages.php`, `Admin\ProductController`, `routes/web.php`, `ProductResource`; `tests/Feature/Store/ProductImageAndCategoryTest.php` |
| Product category (§8) | Rename, hide or show, and delete. The slug never changes. Delete is refused while the category has products, past order lines or a commission rule. Each change is saved and audited in one transaction as `product_category.updated` (only when something changed) and `product_category.deleted`. | `PATCH` and `DELETE /admin/categories/{category}`, `resources/js/pages/admin/ProductsPage.tsx`; `ProductImageAndCategoryTest` |
| Documentation | Administrator and affiliate guides, API guide and OpenAPI 1.2.0, architecture, database schema, migrations, operations, production configuration, known limitations, feature checklist, release readiness, changelog. | `docs/`, [CHANGELOG](../CHANGELOG.md) |

## Design decisions

- **Roles change in place, with a trail.** `StaffAccounts::changeRole` swaps the staff role, signs the person out everywhere so nothing keeps running on the old permissions, and audits `admin.staff_role_changed` with the old and new role. The audit log therefore still shows who did what under which role, and the person keeps one account and one email address. It refuses a change to your own role and demoting the last active Super Administrator.
- **Invitations reuse password reset.** The invitation link is a password-reset token, so it expires with the reset setting (60 minutes) and "Forgot password" works as a fallback. An invitation cannot be resent once the person has signed in; they use "Forgot password" instead. The account is created with a random 64-character password, and its email counts as verified because only the owner can use the link.
- **Guards live in one place.** `StaffAccounts::setStatus` refuses self-disable and disabling the last active Super Administrator, for both the screen and the console.
- **Only programme rules are on screen.** `SettingsRegistry` lists the 18 settings that may change at run time. Secrets, payment keys, mail, retention and upload limits stay in `.env` on purpose.
- **The rest of the code keeps reading `config()`.** `SystemSettings::apply()` runs in `AppServiceProvider::boot` and writes overrides into the config repository. No other class had to change. It does nothing before the table exists, so fresh installs and migrations work.
- **Defaults survive `config:cache`.** `config:cache` boots the app, so the cached `affiliate.*` values can already hold overrides. `SystemSettings` therefore keeps the true `.env` defaults under the config key `system_settings_defaults`, and `apply()` sets every registered setting to its override or its default at each boot. A stale cached override is undone, and **Use default** returns to the `.env` value (`SettingsScreenTest::test_reset_after_config_cache_returns_to_the_env_value_not_the_cached_override`).
- **Every process sees a change.** Overrides are cached under one key. Saving or resetting clears it and sets the same signal as `php artisan queue:restart`, so workers restart after their current job. Web requests pick it up on the next request.
- **Settings change the future only.** A new holding period applies to orders paid afterwards; existing commissions keep their dates.
- **Validation per setting.** Each value is checked against its own type and range, and nothing is saved if any value fails. Values equal to the current one are skipped and not audited.
- **The profile stores no personal data in the audit log.** `user.profile_updated` records only the names of the changed fields.
- **Email is not self-service** because it is how an account is recovered. A stolen session could otherwise take over the account for good.
- **Photo safety.** The type is detected from the content, never the name. SVG and GIF are refused. Files get a new ULID name on every upload, so the public address can be cached for a year (`immutable`). Only a file that a product currently uses is served, with `nosniff` and a `default-src 'none'; sandbox` policy. The route runs without a session or CSRF.
- **Categories keep their slug,** so shared shop links keep working. A category that past orders or commission rules use is hidden, not deleted, so reports stay complete.

## Verification

| Check | Result |
|---|---|
| `php artisan test` on SQLite | **357 passed** |
| `php artisan test` on MySQL 8 | **357 passed** |
| `php artisan test tests/Concurrency` on MySQL | **6 passed** |
| `vendor/bin/pint --test` | pass |
| `php artisan affiliate:permissions-matrix --check` | pass |
| `npm run lint`, `npm run typecheck` | pass |
| `composer audit`, `npm audit --omit=dev` | no advisories |
| OpenAPI lint (Redocly) | valid (style warnings only, as in 1.1.0) |
| `php artisan route:cache` | pass (the `/media/products` closure route caches) |
| Browser check | Staff screen; Settings save, a validation error and **Use default**; My profile; product photo upload; the shop with photos |

New tests: `StaffScreenTest` (6), `SettingsScreenTest` (6), `ProfileTest` (3), `ProductImageAndCategoryTest` (3).

## Not done here, and why

- **No custom roles.** The four staff roles and their permissions stay fixed in code (`PermissionMatrix`). Staff move between them on the Staff screen.
- **No email change by the owner.** See the design decisions. The developer changes it on the server.
- **No integration settings screen.** Payment and mail credentials stay in `.env`.
- **One photo per product.** No gallery, cropping or resizing.
