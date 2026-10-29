import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/format.dart';
import '../../api/models.dart';
import '../../state/cart.dart';
import '../../ui/widgets.dart';
import 'checkout_screen.dart';

class CartScreen extends StatefulWidget {
  const CartScreen({super.key});

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  late final _coupon = TextEditingController(
    text: context.read<Cart>().couponCode ?? '',
  );
  Quote? _quote;
  String? _error;
  String? _couponError;
  bool _quoting = false;
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _requote();
  }

  @override
  void dispose() {
    _coupon.dispose();
    super.dispose();
  }

  /// Asks the server for the real totals. Older answers are ignored if the
  /// cart changed while they were on the way.
  Future<void> _requote() async {
    final cart = context.read<Cart>();
    if (cart.isEmpty) {
      setState(() => _quote = null);
      return;
    }
    final gen = ++_generation;
    setState(() {
      _quoting = true;
      _error = null;
      _couponError = null;
    });
    try {
      final q = await apiOf(context)
          .quote(cart.items, couponCode: cart.couponCode);
      if (!mounted || gen != _generation) return;
      setState(() => _quote = q);
    } on ApiException catch (e) {
      if (!mounted || gen != _generation) return;
      if (e.field('coupon_code') != null) {
        // Show the coupon problem and price the cart without it.
        final message = e.field('coupon_code');
        cart.setCoupon(null);
        await _requote();
        if (mounted) setState(() => _couponError = message);
        return;
      }
      setState(() => _error = e.display);
    } finally {
      if (mounted && gen == _generation) setState(() => _quoting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<Cart>();
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Your cart')),
      body: cart.isEmpty
          ? const Center(
              child: EmptyState(
                icon: Icons.shopping_bag_outlined,
                title: 'Your cart is empty',
                body: 'Add a card or stand from the shop.',
              ),
            )
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                for (final line in cart.lines)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Card(
                      child: Padding(
                        padding: const EdgeInsets.all(10),
                        child: Row(
                          children: [
                            ClipRRect(
                              borderRadius: BorderRadius.circular(10),
                              child: ProductImage(line.imageUrl, size: 64),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    line.name,
                                    style: theme.textTheme.titleSmall,
                                  ),
                                  Text(
                                    money(line.priceMinor, line.currency),
                                    style: theme.textTheme.bodyMedium,
                                  ),
                                ],
                              ),
                            ),
                            IconButton(
                              tooltip: 'One less',
                              icon: Icon(
                                line.quantity == 1
                                    ? Icons.delete_outline
                                    : Icons.remove,
                              ),
                              onPressed: () {
                                cart.setQuantity(
                                  line.productId,
                                  line.quantity - 1,
                                );
                                _requote();
                              },
                            ),
                            Text(
                              '${line.quantity}',
                              style: theme.textTheme.titleMedium,
                            ),
                            IconButton(
                              tooltip: 'One more',
                              icon: const Icon(Icons.add),
                              onPressed: line.quantity < Cart.maxQuantity
                                  ? () {
                                      cart.setQuantity(
                                        line.productId,
                                        line.quantity + 1,
                                      );
                                      _requote();
                                    }
                                  : null,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                const SizedBox(height: 8),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: TextField(
                        controller: _coupon,
                        textCapitalization: TextCapitalization.characters,
                        decoration: InputDecoration(
                          labelText: 'Coupon code',
                          isDense: true,
                          errorText: _couponError,
                          errorMaxLines: 3,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    SizedBox(
                      height: 48,
                      child: OutlinedButton(
                        style: OutlinedButton.styleFrom(
                          minimumSize: const Size(88, 48),
                        ),
                        onPressed: () {
                          cart.setCoupon(_coupon.text);
                          _requote();
                        },
                        child: const Text('Apply'),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                if (_error != null)
                  Text(
                    _error!,
                    style: TextStyle(color: theme.colorScheme.error),
                  ),
                if (_quote != null) _Totals(quote: _quote!, stale: _quoting),
                if (_quote == null && _quoting)
                  const Center(child: CircularProgressIndicator()),
              ],
            ),
      bottomNavigationBar: cart.isEmpty
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: FilledButton(
                  onPressed: (_quote == null || _quoting || _error != null)
                      ? null
                      : () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => const CheckoutScreen(),
                          ),
                        ),
                  child: Text(
                    _quote == null
                        ? 'Checkout'
                        : 'Checkout  ·  ${money(_quote!.totalMinor, _quote!.currency)}',
                  ),
                ),
              ),
            ),
    );
  }
}

class _Totals extends StatelessWidget {
  const _Totals({required this.quote, this.stale = false});
  final Quote quote;
  final bool stale;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    Widget row(String label, int minor, {bool bold = false}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: bold ? theme.textTheme.titleMedium : null,
            ),
          ),
          Text(
            money(minor, quote.currency),
            style: bold
                ? theme.textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  )
                : null,
          ),
        ],
      ),
    );
    return Opacity(
      opacity: stale ? 0.5 : 1,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            children: [
              row('Subtotal', quote.subtotalMinor),
              if (quote.discountMinor > 0)
                row(
                  'Discount${quote.couponCode != null ? ' (${quote.couponCode})' : ''}',
                  -quote.discountMinor,
                ),
              row('Delivery', quote.shippingMinor),
              const Divider(),
              row('Total', quote.totalMinor, bold: true),
            ],
          ),
        ),
      ),
    );
  }
}
