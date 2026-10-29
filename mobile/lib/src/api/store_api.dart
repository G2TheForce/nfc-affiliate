import 'dart:math';
import 'dart:typed_data';

import 'api_client.dart';
import 'models.dart';

/// Typed calls for every endpoint the app uses. Paths follow
/// docs/api-guide.md in the backend repo.
class Api {
  Api(this.client);
  final ApiClient client;

  // ---- Auth -------------------------------------------------------------

  /// Returns the new bearer token and the signed-in user.
  Future<(String, User)> login(
    String email,
    String password,
    String deviceName,
  ) async {
    final body = await client.post(
      'auth/login',
      body: {'email': email, 'password': password, 'device_name': deviceName},
    );
    return (body['token'] as String, User(body['user'] as Json));
  }

  Future<(String, User)> register({
    required String name,
    required String email,
    String? phone,
    required String password,
    required String deviceName,
  }) async {
    final body = await client.post(
      'auth/register',
      body: {
        'name': name,
        'email': email,
        if (phone != null && phone.isNotEmpty) 'phone': phone,
        'password': password,
        'password_confirmation': password,
        'accept_privacy_notice': true,
        'device_name': deviceName,
      },
    );
    return (body['token'] as String, User(body['user'] as Json));
  }

  Future<void> forgotPassword(String email) =>
      client.post('auth/forgot-password', body: {'email': email});

  Future<User> me() async =>
      User((await client.get('auth/me'))['data'] as Json);

  Future<User> updateMe({required String name, String? phone}) async => User(
    (await client.patch(
          'auth/me',
          body: {
            'name': name,
            'phone': (phone == null || phone.isEmpty) ? null : phone,
          },
        ))['data']
        as Json,
  );

  Future<void> changePassword(String current, String password) => client.post(
    'auth/password',
    body: {
      'current_password': current,
      'password': password,
      'password_confirmation': password,
    },
  );

  Future<void> resendVerification() =>
      client.post('auth/email/verification-notification');

  Future<void> logout() => client.post('auth/logout');

  // ---- Notifications ----------------------------------------------------

  Future<(Paginated<AppNotification>, int)> notifications({
    int page = 1,
  }) async {
    final body = await client.get('notifications', query: {'page': page});
    return (
      Paginated.from(body, AppNotification.new),
      (body['meta'] as Json?)?['unread'] as int? ?? 0,
    );
  }

  Future<void> markNotificationRead(String id) =>
      client.post('notifications/$id/read');

  Future<void> markAllNotificationsRead() =>
      client.post('notifications/read-all');

  // ---- Store ------------------------------------------------------------

  Future<List<Category>> categories() async =>
      ((await client.get('store/categories'))['data'] as List)
          .cast<Json>()
          .map(Category.new)
          .toList();

  Future<Paginated<Product>> products({
    int page = 1,
    String? category,
    String? search,
  }) async => Paginated.from(
    await client.get(
      'store/products',
      query: {'page': page, 'category': category, 'search': search},
    ),
    Product.new,
  );

  Future<Product> product(String slug) async =>
      Product((await client.get('store/products/$slug'))['data'] as Json);

  Future<Quote> quote(List<Json> items, {String? couponCode}) async => Quote(
    (await client.post(
          'store/cart/quote',
          body: {
            'items': items,
            if (couponCode != null && couponCode.isNotEmpty)
              'coupon_code': couponCode,
          },
        ))['data']
        as Json,
  );

  /// Places an order. Returns the order and the one-time access token used
  /// to pay for it. Reuse [idempotencyKey] when retrying the same checkout.
  Future<(Order, String)> checkout(Json body, String idempotencyKey) async {
    final res = await client.post(
      'store/orders',
      body: body,
      headers: {'Idempotency-Key': idempotencyKey},
    );
    return (
      Order(res['data'] as Json),
      (res['meta'] as Json)['access_token'] as String,
    );
  }

  Future<List<PaymentMethod>> paymentMethods() async =>
      ((await client.get('store/payment-methods'))['data'] as List)
          .cast<Json>()
          .map(PaymentMethod.new)
          .toList();

  /// Starts an eSewa or Khalti payment. The result is `{type, url, fields?}`.
  Future<Json> startPayment(
    String orderId,
    String provider,
    String? accessToken,
  ) async =>
      ((await client.post(
                'store/orders/$orderId/payments',
                body: {'provider': provider, 'access_token': ?accessToken},
              ))['data']
              as Json)['redirect']
          as Json;

  Future<PaymentStatus> paymentStatus(
    String orderId,
    String? accessToken,
  ) async => PaymentStatus(
    (await client.get(
          'store/orders/$orderId/payment-status',
          query: {'access_token': accessToken},
        ))['data']
        as Json,
  );

  Future<Paginated<Order>> myOrders({int page = 1}) async => Paginated.from(
    await client.get('orders', query: {'page': page}),
    Order.new,
  );

  Future<Order> myOrder(String id) async =>
      Order((await client.get('orders/$id'))['data'] as Json);

  // ---- Tap card ---------------------------------------------------------

  Future<CardProfile?> cardProfile() async {
    final data = (await client.get('card-profile'))['data'];
    return data is Map ? CardProfile(data.cast<String, dynamic>()) : null;
  }

  Future<CardProfile> saveCardProfile(Json body) async => CardProfile(
    (await client.put('card-profile', body: body))['data'] as Json,
  );

  Future<CardStats> cardStats() async =>
      CardStats((await client.get('card-profile/stats'))['data'] as Json);

  // ---- Affiliate membership ---------------------------------------------

  Future<AffiliateProfile?> affiliateProfile() async {
    final data = (await client.get('affiliate/profile'))['data'];
    return data is Map ? AffiliateProfile(data.cast<String, dynamic>()) : null;
  }

  Future<Terms?> currentTerms() async {
    final data = (await client.get('affiliate/terms/current'))['data'];
    return data is Map ? Terms(data.cast<String, dynamic>()) : null;
  }

  Future<void> acceptTerms(String version) => client.post(
    'affiliate/terms/accept',
    body: {'terms_version': version, 'accept_terms': true},
  );

  Future<AffiliateProfile> applyAsAffiliate(Json details) async =>
      AffiliateProfile(
        (await client.post('affiliate/applications', body: details))['data']
            as Json,
      );

  // ---- Affiliate portal -------------------------------------------------

  Future<Dashboard> dashboard() async =>
      Dashboard((await client.get('affiliate/dashboard'))['data'] as Json);

  Future<ReferralLinks> links() async =>
      ReferralLinks((await client.get('affiliate/links'))['data'] as Json);

  Future<ReferralLink> createLink(Json body) async => ReferralLink(
    (await client.post('affiliate/links', body: body))['data'] as Json,
  );

  Future<ReferralLink> setLinkActive(String id, bool active) async =>
      ReferralLink(
        (await client.patch(
              'affiliate/links/$id',
              body: {'is_active': active},
            ))['data']
            as Json,
      );

  Future<List<Campaign>> campaigns() async =>
      ((await client.get('affiliate/campaigns'))['data'] as List)
          .cast<Json>()
          .map(Campaign.new)
          .toList();

  Future<List<MarketingAsset>> marketingAssets() async =>
      ((await client.get('affiliate/marketing-assets'))['data'] as List)
          .cast<Json>()
          .map(MarketingAsset.new)
          .toList();

  Future<(Uint8List, String?)> marketingAssetFile(String id) =>
      client.getBytes('affiliate/marketing-assets/$id/file');

  /// Inline preview of an image material; does not count as a download.
  String marketingAssetPreviewUrl(String id) => client.uri(
    'affiliate/marketing-assets/$id/file',
    {'inline': 1},
  ).toString();

  Future<EarningsSummary> earningsSummary() async => EarningsSummary(
    (await client.get('affiliate/earnings/summary'))['data'] as Json,
  );

  Future<Paginated<Commission>> commissions({
    int page = 1,
    String? status,
  }) async => Paginated.from(
    await client.get(
      'affiliate/earnings/commissions',
      query: {'page': page, 'status': status},
    ),
    Commission.new,
  );

  Future<Paginated<LedgerEntry>> ledger({int page = 1}) async => Paginated.from(
    await client.get('affiliate/earnings/ledger', query: {'page': page}),
    LedgerEntry.new,
  );

  Future<List<ProductRate>> productRates() async =>
      (((await client.get('affiliate/earnings/rates'))['data']
                  as Json)['products']
              as List)
          .cast<Json>()
          .map(ProductRate.new)
          .toList();

  Future<PayoutSummary> payoutSummary() async => PayoutSummary(
    (await client.get('affiliate/payouts/summary'))['data'] as Json,
  );

  Future<Paginated<Payout>> payouts({int page = 1}) async => Paginated.from(
    await client.get('affiliate/payouts', query: {'page': page}),
    Payout.new,
  );

  Future<Payout> requestPayout() async =>
      Payout((await client.post('affiliate/payouts'))['data'] as Json);

  Future<Payout> cancelPayout(String id) async => Payout(
    (await client.post('affiliate/payouts/$id/cancel'))['data'] as Json,
  );

  Future<PayoutMethod> savePayoutMethod(Json body) async => PayoutMethod(
    (await client.put('affiliate/payout-method', body: body))['data'] as Json,
  );
}

/// A fresh key for one checkout attempt (16 to 80 chars of A-Z a-z 0-9 -).
String newIdempotencyKey() {
  final rnd = Random.secure();
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  return 'app-${List.generate(32, (_) => chars[rnd.nextInt(chars.length)]).join()}';
}
