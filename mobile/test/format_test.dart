import 'package:flutter_test/flutter_test.dart';
import 'package:nfc_affiliate_app/src/api/format.dart';
import 'package:nfc_affiliate_app/src/screens/affiliate/links_tab.dart'
    show slugify;
import 'package:nfc_affiliate_app/src/screens/auth/register_screen.dart'
    show normalisePhone;

void main() {
  test('money formats paisa with Nepali grouping', () {
    expect(money(150000), 'Rs 1,500');
    expect(money(12345678), 'Rs 1,23,456.78');
    expect(money(-5050), '-Rs 50.50');
    expect(money(0), 'Rs 0');
    expect(money(100, 'USD'), 'USD 1');
    expect(money(null), '-');
  });

  test('percentBp turns basis points into a percent', () {
    expect(percentBp(1000), '10%');
    expect(percentBp(1250), '12.5%');
    expect(percentBp(1), '0.01%');
  });

  test('normalisePhone accepts local Nepali numbers', () {
    expect(normalisePhone('9812345678'), '+9779812345678');
    expect(normalisePhone('981-234 5678'), '+9779812345678');
    expect(normalisePhone('009779812345678'), '+9779812345678');
    expect(normalisePhone('+9779812345678'), '+9779812345678');
    expect(normalisePhone('  '), isNull);
  });

  test('slugify makes a valid link ending', () {
    expect(slugify('Insta Bio!'), 'insta-bio');
    expect(slugify('  --Facebook  Group 2-- '), 'facebook-group-2');
    expect(slugify('a' * 70).length, 60);
  });
}
