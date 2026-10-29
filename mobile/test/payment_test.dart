import 'package:shared_preferences/shared_preferences.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nfc_affiliate_app/src/config.dart';
import 'package:nfc_affiliate_app/src/screens/shop/payment_screen.dart';

void main() {
  test('recognises the store return page only', () {
    expect(
      isReturnUrl('https://shop.example/order-confirmed?order=1&payment=paid'),
      isTrue,
    );
    expect(
      isReturnUrl('http://10.0.2.2:8000/order-confirmed?payment=unknown'),
      isTrue,
    );
    expect(
      isReturnUrl('https://rc-epay.esewa.com.np/order-confirmed-not'),
      isFalse,
    );
    expect(isReturnUrl('https://pay.khalti.com/?pidx=1'), isFalse);
  });

  test('eSewa form escapes signed fields', () {
    final html = autoSubmitForm('https://esewa.example/form', {
      'signature': 'a"b<c',
      'amount': 100,
    });
    expect(html, contains('action="https://esewa.example/form"'));
    expect(html, contains('name="signature" value="a&quot;b&lt;c"'));
    expect(html, contains('name="amount" value="100"'));
    expect(html, contains('document.forms[0].submit()'));
  });

  test('server links to localhost are pointed at the configured host', () {
    final base = Uri.parse(AppConfig.apiBaseUrl);
    final resolved = Uri.parse(
      AppConfig.resolveUrl('http://localhost:8000/p/asha'),
    );
    expect(resolved.host, base.host);
    expect(resolved.path, '/p/asha');
    expect(
      AppConfig.resolveUrl('https://shop.example/p/asha'),
      'https://shop.example/p/asha',
    );
  });

  test('typed server addresses are normalised', () {
    expect(
      AppConfig.normaliseBaseUrl(' 192.168.1.20:8000 '),
      'http://192.168.1.20:8000',
    );
    expect(
      AppConfig.normaliseBaseUrl('https://shop.example/'),
      'https://shop.example',
    );
    expect(AppConfig.normaliseBaseUrl('ftp://x'), isNull);
    expect(AppConfig.normaliseBaseUrl(''), isNull);
  });

  test('a saved server address replaces the built-in one', () async {
    SharedPreferences.setMockInitialValues({});
    await AppConfig.setApiBaseUrl('http://192.168.1.20:8000');
    expect(AppConfig.apiRoot, 'http://192.168.1.20:8000/api/v1');
    expect(
      AppConfig.resolveUrl('http://localhost:8000/p/asha'),
      'http://192.168.1.20:8000/p/asha',
    );
    await AppConfig.load();
    expect(AppConfig.apiBaseUrl, 'http://192.168.1.20:8000');
    await AppConfig.setApiBaseUrl(null);
    expect(AppConfig.apiBaseUrl, AppConfig.defaultApiBaseUrl);
  });
}
