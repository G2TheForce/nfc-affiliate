// Runs the app's API layer against a real backend, to catch any mismatch
// between the models and what the server sends. Skipped unless LIVE_API_URL
// is set, for example:
//
//   php artisan migrate:fresh --seed && php artisan db:seed --class=DevelopmentSeeder
//   php artisan serve
//   LIVE_API_URL=http://localhost:8000 flutter test test/live_api_test.dart
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:nfc_affiliate_app/src/api/api_client.dart';
import 'package:nfc_affiliate_app/src/api/store_api.dart';

void main() {
  final base = Platform.environment['LIVE_API_URL'];
  final skip = base == null
      ? 'Set LIVE_API_URL to run against a backend'
      : null;
  Api api() => Api(ApiClient(root: '$base/api/v1'));

  test('shop: browse, quote, guest checkout, payment status', () async {
    final a = api();
    final cats = await a.categories();
    final products = await a.products();
    expect(products.items, isNotEmpty);
    if (cats.isNotEmpty) await a.products(category: cats.first.slug);
    final p = await a.product(products.items.first.slug);
    expect(p.priceMinor, greaterThan(0));

    final quote = await a.quote([
      {'product_id': p.id, 'quantity': 2},
    ]);
    expect(
      quote.totalMinor,
      quote.subtotalMinor - quote.discountMinor + quote.shippingMinor,
    );
    expect(quote.lines.single.quantity, 2);

    await a.paymentMethods();
    final key = newIdempotencyKey();
    final body = {
      'items': [
        {'product_id': p.id, 'quantity': 1},
      ],
      'customer_name': 'Live Test',
      'customer_email': 'live-test@dev.invalid',
      'customer_phone': '+9779812345678',
      'shipping_city': 'Kathmandu',
      'shipping_address': 'Test address',
    };
    final (order, token) = await a.checkout(body, key);
    expect(order.awaitingPayment, isTrue);
    expect(order.items.single.quantity, 1);

    // Replaying the same key returns the same order.
    final (again, token2) = await a.checkout(body, key);
    expect(again.id, order.id);

    final status = await a.paymentStatus(order.id, token2);
    expect(status.paid, isFalse);
    await expectLater(
      a.paymentStatus(order.id, token),
      throwsA(isA<ApiException>().having((e) => e.status, 'status', 404)),
    );
  }, skip: skip);

  test('affiliate: every portal screen loads', () async {
    final a = api();
    final (token, user) = await a.login(
      'affiliate@dev.invalid',
      'DevPassword123',
      'live-test',
    );
    a.client.token = token;
    expect(user.canUseAffiliatePortal, isTrue);

    final profile = await a.affiliateProfile();
    expect(profile!.isApproved, isTrue);
    final dash = await a.dashboard();
    expect(dash.series, isNotEmpty);
    final links = await a.links();
    expect(links.mainUrl, contains(links.referralCode));
    await a.campaigns();
    await a.marketingAssets();
    final summary = await a.earningsSummary();
    expect(summary.holdingPeriodDays, greaterThan(0));
    await a.commissions();
    await a.commissions(status: 'pending');
    await a.ledger();
    expect(await a.productRates(), isNotEmpty);
    final payouts = await a.payoutSummary();
    expect(payouts.minimumMinor, greaterThan(0));
    await a.payouts();
    await a.notifications();
    await a.myOrders();

    final slug = 'live-${DateTime.now().millisecondsSinceEpoch}';
    final link = await a.createLink({'name': 'Live test', 'slug': slug});
    expect(link.url, endsWith(slug));
    final off = await a.setLinkActive(link.id, false);
    expect(off.isActive, isFalse);

    final card = await a.saveCardProfile({
      'handle': 'live-test-card',
      'display_name': 'Live Test',
      'theme': 'ocean',
      'is_published': true,
      'socials': {'instagram': '@livetest'},
    });
    expect(card.socials['instagram'], '@livetest');
    expect((await a.cardProfile())!.theme, 'ocean');
    expect((await a.cardStats()).daily, isNotEmpty);

    final method = await a.savePayoutMethod({
      'type': 'khalti',
      'account_name': 'Live Test',
      'wallet_id': '9812345678',
      'password': 'DevPassword123',
    });
    expect(method.masked, contains('5678'));

    await a.logout();
  }, skip: skip);

  test('customer: register, then apply to the affiliate programme', () async {
    final a = api();
    final email = 'live-${DateTime.now().millisecondsSinceEpoch}@dev.invalid';
    final (token, user) = await a.register(
      name: 'Live Signup',
      email: email,
      phone: '+9779812345678',
      password: 'LiveTest-12345',
      deviceName: 'live-test',
    );
    a.client.token = token;
    expect(user.emailVerified, isFalse);
    await expectLater(
      a.affiliateProfile(),
      throwsA(isA<ApiException>().having((e) => e.status, 'status', 403)),
    );

    // The verified dev customer can apply.
    final b = api();
    final (t2, _) = await b.login(
      'customer@dev.invalid',
      'DevPassword123',
      'live-test',
    );
    b.client.token = t2;
    final existing = await b.affiliateProfile();
    if (existing == null) {
      final terms = await b.currentTerms();
      final applied = await b.applyAsAffiliate({
        'display_name': 'Live Customer',
        'country_code': 'NP',
        'city': 'Pokhara',
        'promotion_channels': ['facebook', 'whatsapp'],
        'terms_version': terms!.version,
        'accept_terms': true,
      });
      expect(applied.status, 'pending');
    }
  }, skip: skip);
}
