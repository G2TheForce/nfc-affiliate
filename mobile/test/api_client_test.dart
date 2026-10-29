import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nfc_affiliate_app/src/api/api_client.dart';
import 'package:nfc_affiliate_app/src/api/store_api.dart';

void main() {
  test('sends JSON headers and the bearer token', () async {
    late http.Request seen;
    final client = ApiClient(
      root: 'http://test/api/v1',
      httpClient: MockClient((req) async {
        seen = req;
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'u1',
              'name': 'Asha',
              'email': 'a@b.c',
              'permissions': ['affiliate.portal'],
            },
          }),
          200,
        );
      }),
    )..token = '1|secret';

    final user = await Api(client).me();

    expect(seen.url.toString(), 'http://test/api/v1/auth/me');
    expect(seen.headers['Accept'], 'application/json');
    expect(seen.headers['Authorization'], 'Bearer 1|secret');
    expect(user.name, 'Asha');
    expect(user.canUseAffiliatePortal, isTrue);
  });

  test('turns Laravel validation errors into field messages', () async {
    final client = ApiClient(
      root: 'http://test/api/v1',
      httpClient: MockClient(
        (_) async => http.Response(
          jsonEncode({
            'message': 'The email field is required.',
            'errors': {
              'email': ['The email field is required.'],
            },
          }),
          422,
        ),
      ),
    );

    final error = await Api(client)
        .login('', '', 'test')
        .then<ApiException?>(
          (_) => null,
          onError: (Object e) => e as ApiException,
        );

    expect(error!.status, 422);
    expect(error.field('email'), 'The email field is required.');
    expect(error.display, 'The email field is required.');
  });

  test('reports an expired token once', () async {
    var expired = 0;
    final client =
        ApiClient(
            root: 'http://test/api/v1',
            httpClient: MockClient(
              (_) async => http.Response('{"message":"Unauthenticated."}', 401),
            ),
          )
          ..token = 'old'
          ..onUnauthenticated = () => expired++;

    await expectLater(
      Api(client).me(),
      throwsA(
        isA<ApiException>().having((e) => e.isUnauthenticated, '401', isTrue),
      ),
    );
    expect(expired, 1);
  });

  test('hides server error details', () async {
    final client = ApiClient(
      root: 'http://test/api/v1',
      httpClient: MockClient(
        (_) async => http.Response('{"message":"SQLSTATE..."}', 500),
      ),
    );
    await expectLater(
      Api(client).categories(),
      throwsA(
        isA<ApiException>().having(
          (e) => e.message,
          'message',
          isNot(contains('SQL')),
        ),
      ),
    );
  });

  test(
    'checkout sends the idempotency key and returns the access token',
    () async {
      late http.Request seen;
      final client = ApiClient(
        root: 'http://test/api/v1',
        httpClient: MockClient((req) async {
          seen = req;
          return http.Response(
            jsonEncode({
              'data': {'id': 'o1', 'order_number': 'NFC-1', 'total_minor': 100},
              'meta': {'access_token': 'tok'},
            }),
            201,
          );
        }),
      );
      final key = newIdempotencyKey();

      final (order, token) = await Api(client).checkout({'items': []}, key);

      expect(seen.headers['Idempotency-Key'], key);
      expect(RegExp(r'^[A-Za-z0-9-]{16,80}$').hasMatch(key), isTrue);
      expect(order.number, 'NFC-1');
      expect(token, 'tok');
    },
  );

  test('query parameters skip empty values', () {
    final client = ApiClient(root: 'http://test/api/v1');
    expect(
      client.uri('store/products', {
        'page': 1,
        'category': null,
        'search': '',
      }).toString(),
      'http://test/api/v1/store/products?page=1',
    );
  });
}
