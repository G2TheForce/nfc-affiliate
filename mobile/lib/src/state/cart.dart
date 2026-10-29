import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/models.dart';

class CartLine {
  CartLine({
    required this.productId,
    required this.name,
    required this.priceMinor,
    required this.currency,
    this.imageUrl,
    this.quantity = 1,
  });

  final String productId;
  final String name;
  final int priceMinor;
  final String currency;
  final String? imageUrl;
  int quantity;

  Json toJson() => {
    'product_id': productId,
    'name': name,
    'price_minor': priceMinor,
    'currency': currency,
    'image_url': imageUrl,
    'quantity': quantity,
  };

  static CartLine fromJson(Json j) => CartLine(
    productId: j['product_id'] as String,
    name: j['name'] as String? ?? '',
    priceMinor: j['price_minor'] as int? ?? 0,
    currency: j['currency'] as String? ?? 'NPR',
    imageUrl: j['image_url'] as String?,
    quantity: j['quantity'] as int? ?? 1,
  );
}

/// The shopping cart, kept on the phone. Prices shown here are a guide; the
/// server's quote is what the customer pays.
class Cart extends ChangeNotifier {
  Cart({this.persist = true});

  static const maxQuantity = 100;
  static const _prefsKey = 'cart_v1';
  final bool persist;
  final List<CartLine> _lines = [];
  String? couponCode;

  List<CartLine> get lines => List.unmodifiable(_lines);
  bool get isEmpty => _lines.isEmpty;
  int get count => _lines.fold(0, (sum, l) => sum + l.quantity);
  int get estimatedTotalMinor =>
      _lines.fold(0, (sum, l) => sum + l.priceMinor * l.quantity);

  /// Lines in the shape POST /store/cart/quote and /store/orders expect.
  List<Json> get items => _lines
      .map((l) => {'product_id': l.productId, 'quantity': l.quantity})
      .toList();

  Future<void> load() async {
    if (!persist) return;
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_prefsKey);
    if (raw == null) return;
    try {
      final decoded = jsonDecode(raw) as Map<String, dynamic>;
      _lines
        ..clear()
        ..addAll(
          (decoded['lines'] as List).cast<Json>().map(CartLine.fromJson),
        );
      couponCode = decoded['coupon'] as String?;
      notifyListeners();
    } catch (_) {
      await prefs.remove(_prefsKey);
    }
  }

  void add(Product product, {int quantity = 1}) {
    final existing = _lines.where((l) => l.productId == product.id).firstOrNull;
    if (existing != null) {
      existing.quantity = (existing.quantity + quantity).clamp(1, maxQuantity);
    } else {
      _lines.add(
        CartLine(
          productId: product.id,
          name: product.name,
          priceMinor: product.priceMinor,
          currency: product.currency,
          imageUrl: product.imageUrl,
          quantity: quantity.clamp(1, maxQuantity),
        ),
      );
    }
    _changed();
  }

  void setQuantity(String productId, int quantity) {
    if (quantity <= 0) {
      _lines.removeWhere((l) => l.productId == productId);
    } else {
      for (final l in _lines) {
        if (l.productId == productId) {
          l.quantity = quantity.clamp(1, maxQuantity);
        }
      }
    }
    _changed();
  }

  void setCoupon(String? code) {
    couponCode = (code == null || code.trim().isEmpty)
        ? null
        : code.trim().toUpperCase();
    _changed();
  }

  void clear() {
    _lines.clear();
    couponCode = null;
    _changed();
  }

  void _changed() {
    notifyListeners();
    if (!persist) return;
    SharedPreferences.getInstance().then(
      (prefs) => prefs.setString(
        _prefsKey,
        jsonEncode({
          'lines': _lines.map((l) => l.toJson()).toList(),
          'coupon': couponCode,
        }),
      ),
    );
  }
}
