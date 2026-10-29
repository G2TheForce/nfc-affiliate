import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/format.dart';
import '../../api/models.dart';
import '../../state/cart.dart';
import '../../ui/widgets.dart';
import 'cart_screen.dart';
import 'shop_screen.dart' show CartButton;

class ProductScreen extends StatefulWidget {
  const ProductScreen({super.key, required this.slug, this.initial});
  final String slug;
  final Product? initial;

  @override
  State<ProductScreen> createState() => _ProductScreenState();
}

class _ProductScreenState extends State<ProductScreen> {
  int _quantity = 1;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.initial?.name ?? 'Product'),
        actions: const [CartButton()],
      ),
      body: LoadView<Product>(
        load: () => apiOf(context).product(widget.slug),
        builder: (context, product, _) => _details(context, product),
      ),
    );
  }

  Widget _details(BuildContext context, Product product) {
    final theme = Theme.of(context);
    return Column(
      children: [
        Expanded(
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              AspectRatio(
                aspectRatio: 1.2,
                child: Hero(
                  tag: 'product-${product.id}',
                  child: ProductImage(product.imageUrl),
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (product.category != null)
                      Text(
                        product.category!.name,
                        style: theme.textTheme.labelLarge?.copyWith(
                          color: theme.colorScheme.primary,
                        ),
                      ),
                    const SizedBox(height: 4),
                    Text(
                      product.name,
                      style: theme.textTheme.headlineSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      money(product.priceMinor, product.currency),
                      style: theme.textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    if (product.description != null &&
                        product.description!.isNotEmpty) ...[
                      const SizedBox(height: 16),
                      Text(
                        product.description!,
                        style: theme.textTheme.bodyLarge,
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: Row(
              children: [
                IconButton.outlined(
                  onPressed: _quantity > 1
                      ? () => setState(() => _quantity--)
                      : null,
                  icon: const Icon(Icons.remove),
                ),
                SizedBox(
                  width: 40,
                  child: Text(
                    '$_quantity',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                IconButton.outlined(
                  onPressed: _quantity < Cart.maxQuantity
                      ? () => setState(() => _quantity++)
                      : null,
                  icon: const Icon(Icons.add),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: FilledButton.icon(
                    icon: const Icon(Icons.add_shopping_cart),
                    label: const Text('Add to cart'),
                    onPressed: () {
                      context.read<Cart>().add(product, quantity: _quantity);
                      ScaffoldMessenger.of(context)
                        ..hideCurrentSnackBar()
                        ..showSnackBar(
                          SnackBar(
                            content: Text('Added ${product.name}'),
                            duration: const Duration(seconds: 3),
                            // Snack bars with an action stay up by default.
                            persist: false,
                            action: SnackBarAction(
                              label: 'View cart',
                              onPressed: () => Navigator.of(context).push(
                                MaterialPageRoute(
                                  builder: (_) => const CartScreen(),
                                ),
                              ),
                            ),
                          ),
                        );
                    },
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
