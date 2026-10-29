import 'format.dart';

typedef Json = Map<String, dynamic>;

int _int(Object? v) =>
    v is int ? v : (v is num ? v.toInt() : int.tryParse('$v') ?? 0);
int? _intOrNull(Object? v) => v == null ? null : _int(v);
String? _str(Object? v) => v?.toString();
List<Json> _list(Object? v) => v is List
    ? v.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList()
    : const [];
Json _map(Object? v) => v is Map ? v.cast<String, dynamic>() : const {};

class User {
  User(this.json);
  final Json json;

  String get id => json['id'] as String;
  String get name => json['name'] as String? ?? '';
  String get email => json['email'] as String? ?? '';
  String? get phone => _str(json['phone']);
  bool get emailVerified => json['email_verified'] == true;
  bool get isStaff => json['is_staff'] == true;
  List<String> get roles =>
      (json['roles'] as List? ?? const []).map((e) => '$e').toList();
  List<String> get permissions =>
      (json['permissions'] as List? ?? const []).map((e) => '$e').toList();

  bool get canUseAffiliatePortal => permissions.contains('affiliate.portal');
  bool get canRequestPayouts =>
      permissions.contains('affiliate.payouts.request');
}

class Category {
  Category(this.json);
  final Json json;
  int get id => _int(json['id']);
  String get name => json['name'] as String? ?? '';
  String get slug => json['slug'] as String? ?? '';
}

class Product {
  Product(this.json);
  final Json json;

  String get id => json['id'] as String;
  String get name => json['name'] as String? ?? '';
  String get slug => json['slug'] as String? ?? '';
  String? get description => _str(json['description']);
  String? get imageUrl => _str(json['image_url']);
  int get priceMinor => _int(json['price_minor']);
  String get currency => json['currency'] as String? ?? 'NPR';
  Category? get category =>
      json['category'] is Map ? Category(_map(json['category'])) : null;
}

class Paginated<T> {
  Paginated(this.items, this.currentPage, this.lastPage);
  final List<T> items;
  final int currentPage;
  final int lastPage;
  bool get hasMore => currentPage < lastPage;

  static Paginated<T> from<T>(Json body, T Function(Json) item) {
    final meta = _map(body['meta']);
    return Paginated(
      _list(body['data']).map(item).toList(),
      _int(meta['current_page'] ?? 1),
      _int(meta['last_page'] ?? 1),
    );
  }
}

class QuoteLine {
  QuoteLine(this.json);
  final Json json;
  String get productId => json['product_id'] as String;
  String get name => json['name'] as String? ?? '';
  int get unitPriceMinor => _int(json['unit_price_minor']);
  int get quantity => _int(json['quantity']);
  int get subtotalMinor => _int(json['subtotal_minor']);
}

class Quote {
  Quote(this.json);
  final Json json;
  String get currency => json['currency'] as String? ?? 'NPR';
  int get subtotalMinor => _int(json['subtotal_minor']);
  int get discountMinor => _int(json['discount_minor']);
  int get shippingMinor => _int(json['shipping_minor']);
  int get totalMinor => _int(json['total_minor']);
  String? get couponCode => _str(json['coupon_code']);
  List<QuoteLine> get lines => _list(json['lines']).map(QuoteLine.new).toList();
}

class OrderItem {
  OrderItem(this.json);
  final Json json;
  String get name => json['name'] as String? ?? '';
  int get quantity => _int(json['quantity']);
  int get unitPriceMinor => _int(json['unit_price_minor']);
  int get lineTotalMinor => _int(json['line_total_minor']);
}

class Order {
  Order(this.json);
  final Json json;

  String get id => json['id'] as String;
  String get number => json['order_number'] as String? ?? '';
  String get status => json['status'] as String? ?? '';
  String get statusLabel => json['status_label'] as String? ?? status;
  String get fulfilmentLabel => json['fulfilment_label'] as String? ?? '';
  String? get carrier => _str(json['carrier']);
  String? get trackingNumber => _str(json['tracking_number']);
  String get currency => json['currency'] as String? ?? 'NPR';
  int get subtotalMinor => _int(json['subtotal_minor']);
  int get discountMinor => _int(json['discount_minor']);
  int get shippingMinor => _int(json['shipping_minor']);
  int get totalMinor => _int(json['total_minor']);
  String? get couponCode => _str(json['coupon_code']);
  String get customerName => json['customer_name'] as String? ?? '';
  String get shippingCity => json['shipping_city'] as String? ?? '';
  String get shippingAddress => json['shipping_address'] as String? ?? '';
  DateTime? get placedAt => parseTime(json['placed_at']);
  DateTime? get shippedAt => parseTime(json['shipped_at']);
  DateTime? get deliveredAt => parseTime(json['delivered_at']);
  List<OrderItem> get items => _list(json['items']).map(OrderItem.new).toList();
  bool get awaitingPayment => status == 'pending_payment';
}

class PaymentMethod {
  PaymentMethod(this.json);
  final Json json;
  String get key => json['key'] as String;
  String get label => json['label'] as String? ?? key;
  int get minimumMinor => _int(json['minimum_minor']);
}

class PaymentStatus {
  PaymentStatus(this.json);
  final Json json;
  bool get paid => json['paid'] == true;
  String get orderStatusLabel => json['order_status_label'] as String? ?? '';
  String? get outcome => _str(_map(json['attempt'])['outcome']);
  bool get failed => outcome == 'failed';
}

class CardProfile {
  CardProfile(this.json);
  final Json json;

  static const themes = [
    'aurora',
    'violet',
    'teal',
    'gold',
    'graphite',
    'ocean',
  ];
  static const socialNetworks = [
    'facebook',
    'instagram',
    'linkedin',
    'tiktok',
    'youtube',
    'x',
  ];

  String get handle => json['handle'] as String? ?? '';
  String get displayName => json['display_name'] as String? ?? '';
  String? get headline => _str(json['headline']);
  String? get company => _str(json['company']);
  String? get bio => _str(json['bio']);
  String? get phone => _str(json['phone']);
  String? get whatsapp => _str(json['whatsapp']);
  String? get email => _str(json['email']);
  String? get website => _str(json['website']);
  String? get location => _str(json['location']);
  String get theme => json['theme'] as String? ?? 'aurora';
  Map<String, String> get socials =>
      _map(json['socials']).map((k, v) => MapEntry(k, '$v'));
  bool get isPublished => json['is_published'] == true;
  int get tapsTotal => _int(json['taps_total']);
  int get savesTotal => _int(json['saves_total']);
  String get publicUrl => json['public_url'] as String? ?? '';
}

class CardStats {
  CardStats(this.json);
  final Json json;
  int get tapsTotal => _int(json['taps_total']);
  int get savesTotal => _int(json['saves_total']);
  List<({String day, int taps, int saves})> get daily => _list(json['daily'])
      .map(
        (d) => (
          day: d['day'] as String? ?? '',
          taps: _int(d['taps']),
          saves: _int(d['saves']),
        ),
      )
      .toList();
}

class AffiliateProfile {
  AffiliateProfile(this.json);
  final Json json;

  String get status => json['status'] as String? ?? '';
  String get statusLabel => json['status_label'] as String? ?? status;
  String get displayName => json['display_name'] as String? ?? '';
  String get referralCode => json['referral_code'] as String? ?? '';
  bool get needsTermsAcceptance => json['needs_terms_acceptance'] == true;
  String? get currentTermsVersion => _str(json['current_terms_version']);
  DateTime? get canReapplyAt => parseTime(json['can_reapply_at']);
  bool get isApproved => status == 'approved';

  /// The reason staff gave with the latest status change, if any.
  String? get latestReason {
    final history = _list(json['history']);
    return history.isEmpty ? null : _str(history.first['reason']);
  }
}

class Terms {
  Terms(this.json);
  final Json json;
  String get version => json['version'] as String? ?? '';
  String get title => json['title'] as String? ?? '';
  String get body => json['body'] as String? ?? '';
}

class ReferralLink {
  ReferralLink(this.json);
  final Json json;
  String get id => json['id'] as String;
  String get name => json['name'] as String? ?? '';
  String get slug => json['slug'] as String? ?? '';
  String get url => json['url'] as String? ?? '';
  String? get productName => _str(_map(json['product'])['name']);
  String? get campaignName => _str(_map(json['campaign'])['name']);
  bool get isActive => json['is_active'] != false;
}

class ReferralLinks {
  ReferralLinks(this.json);
  final Json json;
  String get referralCode => json['referral_code'] as String? ?? '';
  String get mainUrl => json['main_url'] as String? ?? '';
  bool get canCreate => json['can_create'] == true;
  int get windowDays => _int(_map(json['attribution'])['window_days']);
  List<ReferralLink> get links =>
      _list(json['links']).map(ReferralLink.new).toList();
}

class Balances {
  Balances(this.json);
  final Json json;
  int get pending => _int(json['pending']);
  int get approved => _int(json['approved']);
  int get available => _int(json['available']);
  int get reserved => _int(json['reserved']);
  int get paid => _int(json['paid']);
  int get reversed => _int(json['reversed']);
}

class Dashboard {
  Dashboard(this.json);
  final Json json;
  String get currency => json['currency'] as String? ?? 'NPR';
  Json get totals => _map(json['totals']);
  int get clicks => _int(totals['clicks']);
  int get attributedOrders => _int(totals['attributed_orders']);
  int get paidOrders => _int(totals['paid_orders']);
  int get revenueMinor => _int(totals['revenue_minor']);
  int get earnedMinor => _int(totals['commission_earned_minor']);

  /// Orders per 100 unique clicks (a percent), or null when there were no clicks.
  num? get conversionRate => totals['conversion_rate'] as num?;
  Balances get balances => Balances(_map(json['balances']));
  List<({String bucket, int clicks, int orders, int earnedMinor})> get series =>
      _list(json['series'])
          .map(
            (s) => (
              bucket: s['bucket'] as String? ?? '',
              clicks: _int(s['clicks']),
              orders: _int(s['orders']),
              earnedMinor: _int(s['earned_minor']),
            ),
          )
          .toList();
}

class Commission {
  Commission(this.json);
  final Json json;
  String get orderNumber => json['order_number'] as String? ?? '';
  String get productName => json['product_name'] as String? ?? '';
  int get quantity => _int(json['quantity']);
  int get netMinor => _int(json['net_minor']);
  int get reversedMinor => _int(json['reversed_minor']);
  String get currency => json['currency'] as String? ?? 'NPR';
  String get status => json['status'] as String? ?? '';
  String get statusLabel => json['status_label'] as String? ?? status;
  bool get onHold => json['on_hold'] == true;
  DateTime? get eligibleAt => parseTime(json['eligible_at']);
  DateTime? get createdAt => parseTime(json['created_at']);
}

class LedgerEntry {
  LedgerEntry(this.json);
  final Json json;
  String get label => json['label'] as String? ?? '';
  int get amountMinor => _int(json['amount_minor']);
  String get currency => json['currency'] as String? ?? 'NPR';
  String? get toBucket => _str(json['to_bucket']);
  DateTime? get createdAt => parseTime(json['created_at']);
}

class EarningsSummary {
  EarningsSummary(this.json);
  final Json json;
  String get currency => json['currency'] as String? ?? 'NPR';
  Balances get balances => Balances(_map(json['balances']));
  int get holdingPeriodDays => _int(json['holding_period_days']);
  DateTime? get nextEligibleAt => parseTime(json['next_eligible_at']);
}

class PayoutMethod {
  PayoutMethod(this.json);
  final Json json;
  String get type => json['type'] as String? ?? '';
  String get typeLabel => json['type_label'] as String? ?? type;
  String get accountName => json['account_name'] as String? ?? '';
  String get masked => json['masked'] as String? ?? '';
}

class Payout {
  Payout(this.json);
  final Json json;
  String get id => json['id'] as String;
  int get amountMinor => _int(json['amount_minor']);
  String get currency => json['currency'] as String? ?? 'NPR';
  String get status => json['status'] as String? ?? '';
  String get statusLabel => json['status_label'] as String? ?? status;
  String get methodLabel => json['method_label'] as String? ?? '';
  String? get destination => _str(json['destination']);
  String? get paymentReference => _str(json['payment_reference']);
  String? get reason => _str(json['reason']);
  DateTime? get requestedAt => parseTime(json['requested_at']);
  DateTime? get paidAt => parseTime(json['paid_at']);
  bool get canCancel => json['can_cancel'] == true;
}

class PayoutSummary {
  PayoutSummary(this.json);
  final Json json;
  String get currency => json['currency'] as String? ?? 'NPR';
  int get availableMinor => _int(json['available_minor']);
  int get reservedMinor => _int(json['reserved_minor']);
  int get paidMinor => _int(json['paid_minor']);
  int get minimumMinor => _int(json['minimum_minor']);
  PayoutMethod? get method =>
      json['method'] is Map ? PayoutMethod(_map(json['method'])) : null;
  Payout? get openPayout =>
      json['open_payout'] is Map ? Payout(_map(json['open_payout'])) : null;
  bool get canRequest => json['can_request'] == true;
  String? get blocker => _str(json['blocker']);
}

class Campaign {
  Campaign(this.json);
  final Json json;
  String get id => json['id'] as String;
  String get name => json['name'] as String? ?? '';
  String? get description => _str(json['description']);
  DateTime? get endsAt => parseTime(json['ends_at']);
  Json get commission => _map(json['commission']);
  List<String> get couponCodes =>
      _list(json['coupons']).map((c) => '${c['code']}').toList();

  String? get commissionText {
    if (commission.isEmpty) return null;
    return commission['kind'] == 'fixed'
        ? '${money(_intOrNull(commission['fixed_minor']), commission['currency'] as String? ?? 'NPR')} per item'
        : '${percentBp(_intOrNull(commission['rate_bp']))} commission';
  }
}

class MarketingAsset {
  MarketingAsset(this.json);
  final Json json;
  String get id => json['id'] as String;
  String get title => json['title'] as String? ?? '';
  String? get description => _str(json['description']);
  String get kind => json['kind'] as String? ?? 'text';
  String? get caption => _str(json['caption']);
  bool get hasFile => json['has_file'] == true;
  String? get campaignName => _str(_map(json['campaign'])['name']);
}

class AppNotification {
  AppNotification(this.json);
  final Json json;
  String get id => json['id'] as String;
  String get title => json['title'] as String? ?? '';
  String get body => json['body'] as String? ?? '';
  bool get read => json['read'] == true;
  DateTime? get createdAt => parseTime(json['created_at']);
}

class ProductRate {
  ProductRate(this.json);
  final Json json;
  String get name => json['name'] as String? ?? '';
  bool get earns => json['earns'] == true;
  int get perUnitMinor => _int(json['commission_per_unit_minor']);
  String get currency => json['currency'] as String? ?? 'NPR';
}
