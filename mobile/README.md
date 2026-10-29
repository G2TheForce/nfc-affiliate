# NFC Card Store mobile app (Flutter)

The Android and iOS app for the store and the affiliate programme. It talks to the Laravel API in this repo (`/api/v1`, see [docs/api-guide.md](../docs/api-guide.md)) with a Sanctum bearer token.

## What it does

| Tab | Who sees it | What is in it |
|---|---|---|
| Shop | Everyone | Products and categories, search, cart with the server's quote and coupons, checkout (guest or signed in), eSewa and Khalti payment in an in-app browser, payment confirmation |
| Affiliate | Signed in | Apply to join (with the current terms), application status, accept changed terms. Approved members get: overview (balances, last 30 days, earnings chart), referral links (copy, share, QR, create, turn off), running campaigns and their coupons, earnings (commissions by status, ledger history, per-product rates), payouts (request, cancel, payout method), marketing materials (copy captions, share files) |
| Tap card | Signed in, email confirmed | Card preview, public link with QR and share, taps and saves, full profile editor (contact, socials, theme, published) |
| Account | Everyone | Sign in, create account, forgot password, my orders and order detail (with Pay now), notifications, edit name and phone, change password, sign out |

Staff and admin work stays on the web app.

How it behaves:

- **Sign-in.** Login sends `device_name`, and the token is kept in the phone's secure storage (Keychain / Android Keystore). It lasts 30 days. A 401 signs the person out.
- **Money.** Amounts are integer paisa from the API and are shown as `Rs 1,23,456.78`. The app never does money maths with doubles.
- **Checkout.** Each checkout screen uses one `Idempotency-Key`, so a retry on a bad connection cannot create a second order.
- **Payments.** eSewa needs a signed form POST, so the app builds the form in a web view and submits it. Khalti opens its URL. When the provider sends the browser back to `/order-confirmed`, the app closes the web view and polls `payment-status` every 4 seconds for up to 2 minutes. The provider page is never taken as proof of payment.
- **Referrals.** The app has no `nfc_ref` cookie, so checkout has a "Referral code" field.

## Run it on your Windows PC

You need:

1. The backend running: `php artisan serve` (on <http://localhost:8000>). See [docs/local-setup-windows.md](../docs/local-setup-windows.md).
2. [Flutter](https://docs.flutter.dev/get-started/install/windows/mobile) 3.47 or newer, and Android Studio with an Android emulator (Device Manager > Create device).

Then, in PowerShell:

```powershell
cd C:\Users\jk47\nfc-affiliate\mobile
flutter pub get
flutter run
```

The Android emulator reaches your PC's `localhost` as `10.0.2.2`, so the app uses `http://10.0.2.2:8000` by default. Sign in with a development account such as `affiliate@dev.invalid` / `DevPassword123` (created by `php artisan db:seed --class=DevelopmentSeeder`).

### On a real phone

The phone must be on the same Wi-Fi as the PC.

1. Find the PC's address: `ipconfig` and look for the IPv4 address, for example `192.168.1.20`.
2. Start the backend so other devices can reach it: `php artisan serve --host=0.0.0.0 --port=8000`. Allow it through Windows Firewall when asked.
3. Run the app with that address:

   ```powershell
   flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000
   ```

### Without installing Flutter: download an APK

GitHub builds an installable APK for you:

1. On GitHub, open **Actions > Mobile APK > Run workflow**.
2. Enter the server address the phone should use (your PC's LAN address as above, or the live `https://` site), then run it.
3. When it finishes, download **nfc-card-store-apk** from the run's Artifacts, unzip it, copy `app-release.apk` to the phone and open it. Android asks you to allow installing from that source.

That APK is signed with a debug key. It is fine for testing, but not for the Play Store (see below).

## Settings

| `--dart-define` | Default | Meaning |
|---|---|---|
| `API_BASE_URL` | `http://10.0.2.2:8000` | The backend, without `/api/v1` |

The server builds links (referral links, tap profile links, product photos) from its `APP_URL`. Locally that is `http://localhost:8000`, which on a phone means the phone itself, so the app rewrites `localhost` links to the `API_BASE_URL` host. In production the links are already correct.

Plain `http` is allowed in debug builds only. A release build refuses it unless it is built with `--android-project-arg=cleartext=true`, which the APK workflow does automatically for `http://` addresses. Production must use `https`.

## Develop

```powershell
flutter analyze
dart format lib test
flutter test
```

`test/live_api_test.dart` runs the app's API layer against a real backend, to catch any mismatch between the app's models and the server. It is skipped unless `LIVE_API_URL` is set:

```bash
php artisan migrate:fresh --seed && php artisan db:seed --class=DevelopmentSeeder
php artisan serve
cd mobile && LIVE_API_URL=http://localhost:8000 flutter test test/live_api_test.dart
```

Code layout:

```
lib/
  main.dart                 providers and start-up
  src/config.dart           API_BASE_URL and link rewriting
  src/api/                  HTTP client, typed endpoints, models, money/date formatting
  src/state/                Session (token, user) and Cart
  src/ui/                   theme and shared widgets
  src/screens/              shop, affiliate, card, account, auth
```

## Before publishing to the stores

- Pick the final app id. It is `com.nfcaffiliate.nfc_affiliate_app` now (`android/app/build.gradle.kts`, and the bundle id in Xcode). Once the app is published, the id cannot change.
- Replace the Flutter launcher icon and splash screen.
- Create an upload key and a release signing config (Android), and an Apple developer account and signing (iOS).
- Point `API_BASE_URL` at the live `https://` site.
- Add the store listing, privacy policy link and screenshots.
