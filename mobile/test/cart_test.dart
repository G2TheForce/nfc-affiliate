import 'package:flutter_test/flutter_test.dart';
import 'package:nfc_affiliate_app/src/api/models.dart';
import 'package:nfc_affiliate_app/src/state/cart.dart';

Product product(String id, int price) => Product({
  'id': id,
  'name': 'Card $id',
  'slug': id,
  'price_minor': price,
  'currency': 'NPR',
});

void main() {
  test('adds, merges and removes lines', () {
    final cart = Cart(persist: false)
      ..add(product('a', 1000))
      ..add(product('a', 1000), quantity: 2)
      ..add(product('b', 500));

    expect(cart.count, 4);
    expect(cart.estimatedTotalMinor, 3500);
    expect(cart.items, [
      {'product_id': 'a', 'quantity': 3},
      {'product_id': 'b', 'quantity': 1},
    ]);

    cart.setQuantity('a', 0);
    expect(cart.lines.map((l) => l.productId), ['b']);
  });

  test('caps quantity and normalises coupon codes', () {
    final cart = Cart(persist: false)..add(product('a', 1000), quantity: 500);
    expect(cart.count, Cart.maxQuantity);

    cart.setCoupon('  asha10 ');
    expect(cart.couponCode, 'ASHA10');
    cart.setCoupon('');
    expect(cart.couponCode, isNull);

    cart.clear();
    expect(cart.isEmpty, isTrue);
  });
}
