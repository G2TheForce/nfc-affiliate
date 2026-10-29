import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nfc_affiliate_app/src/api/api_client.dart';
import 'package:nfc_affiliate_app/src/api/store_api.dart';
import 'package:nfc_affiliate_app/src/app.dart';
import 'package:nfc_affiliate_app/src/state/cart.dart';
import 'package:nfc_affiliate_app/src/state/session.dart';
import 'package:provider/provider.dart';

class MemoryTokens implements TokenStore {
  String? token;
  @override
  Future<String?> read() async => token;
  @override
  Future<void> write(String t) async => token = t;
  @override
  Future<void> clear() async => token = null;
}

const _user = {
  'id': 'u1',
  'name': 'Asha Rai',
  'email': 'asha@example.com',
  'email_verified': true,
  'roles': ['customer', 'affiliate'],
  'permissions': [
    'affiliate.portal',
    'affiliate.payouts.request',
    'customer.portal',
  ],
};

const _product = {
  'id': 'p1',
  'name': 'Metal NFC card',
  'slug': 'metal-nfc-card',
  'price_minor': 350000,
  'currency': 'NPR',
  'image_url': null,
  'category': {'id': 1, 'name': 'NFC cards', 'slug': 'nfc-cards'},
};

/// A tiny stand-in for the Laravel API with the response shapes it really sends.
http.Response _fake(http.Request req) {
  final path = req.url.path.replaceFirst('/api/v1/', '');
  Object? body = switch (path) {
    'store/categories' => {
      'data': [_product['category']],
    },
    'store/products' => {
      'data': [_product],
      'meta': {'current_page': 1, 'last_page': 1},
    },
    'auth/login' => {'token': '1|t', 'token_type': 'Bearer', 'user': _user},
    'auth/me' => {'data': _user},
    'affiliate/profile' => {
      'data': {
        'status': 'approved',
        'status_label': 'Approved',
        'display_name': 'Asha Rai',
        'referral_code': 'ASHA01',
        'needs_terms_acceptance': false,
        'history': [],
      },
    },
    'affiliate/dashboard' => {
      'data': {
        'currency': 'NPR',
        'totals': {
          'clicks': 12,
          'attributed_orders': 3,
          'paid_orders': 2,
          'revenue_minor': 700000,
          'commission_earned_minor': 70000,
          'conversion_rate': 25.0,
        },
        'balances': {
          'pending': 70000,
          'approved': 0,
          'available': 150000,
          'reserved': 0,
          'paid': 0,
          'reversed': 0,
        },
        'series': [
          {'bucket': '2026-10-28', 'clicks': 5, 'orders': 1, 'earned_minor': 0},
          {
            'bucket': '2026-10-29',
            'clicks': 7,
            'orders': 2,
            'earned_minor': 70000,
          },
        ],
      },
    },
    'affiliate/links' => {
      'data': {
        'referral_code': 'ASHA01',
        'main_url': 'https://shop.example/ref/ASHA01',
        'can_create': true,
        'attribution': {'window_days': 30},
        'links': [],
      },
    },
    _ => null,
  };
  return body == null
      ? http.Response('{"message":"Not found."}', 404)
      : http.Response(jsonEncode(body), 200);
}

Widget _app(Session session) => MultiProvider(
  providers: [
    Provider<Api>.value(value: session.api),
    ChangeNotifierProvider<Session>.value(value: session),
    ChangeNotifierProvider<Cart>.value(value: Cart(persist: false)),
  ],
  child: const NfcApp(),
);

void main() {
  testWidgets(
    'guest browses the shop, signs in and sees the affiliate dashboard',
    (tester) async {
      final api = Api(
        ApiClient(
          root: 'http://test/api/v1',
          httpClient: MockClient((req) async => _fake(req)),
        ),
      );
      final tokens = MemoryTokens();
      final session = Session(api, tokens);
      await tester.pumpWidget(_app(session));
      await session.restore();
      await tester.pumpAndSettle();

      // Guests get Shop and Account only.
      expect(find.text('Metal NFC card'), findsOneWidget);
      expect(find.text('Rs 3,500'), findsOneWidget);
      expect(find.text('Affiliate'), findsNothing);

      await tester.tap(find.text('Account'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.widgetWithText(TextFormField, 'Email'),
        'asha@example.com',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'Password'),
        'Secret-password-1',
      );
      await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
      await tester.pumpAndSettle();

      expect(tokens.token, '1|t');
      expect(find.text('Asha Rai'), findsOneWidget);
      expect(find.text('Tap card'), findsOneWidget);

      await tester.tap(find.text('Affiliate'));
      await tester.pumpAndSettle();
      expect(find.text('https://shop.example/ref/ASHA01'), findsOneWidget);
      expect(find.text('Rs 1,500'), findsOneWidget); // available balance
      expect(find.text('Rs 700'), findsWidgets); // earned
    },
  );

  testWidgets('an expired saved token signs the person out', (tester) async {
    final api = Api(
      ApiClient(
        root: 'http://test/api/v1',
        httpClient: MockClient(
          (_) async => http.Response('{"message":"Unauthenticated."}', 401),
        ),
      ),
    );
    final tokens = MemoryTokens()..token = 'old';
    final session = Session(api, tokens);
    await tester.pumpWidget(_app(session));
    await session.restore();
    await tester.pumpAndSettle();

    expect(session.signedIn, isFalse);
    expect(tokens.token, isNull);
  });
}
