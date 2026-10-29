import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/format.dart';
import '../../api/models.dart';
import '../../state/cart.dart';
import '../../ui/widgets.dart';
import 'cart_screen.dart';
import 'product_screen.dart';

class ShopScreen extends StatefulWidget {
  const ShopScreen({super.key});

  @override
  State<ShopScreen> createState() => _ShopScreenState();
}

class _ShopScreenState extends State<ShopScreen> {
  final _search = TextEditingController();
  Timer? _debounce;
  List<Category> _categories = const [];
  String? _category;
  final List<Product> _products = [];
  int _page = 1;
  bool _hasMore = false;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadCategories();
    _reload();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _loadCategories() async {
    try {
      final cats = await apiOf(context).categories();
      if (mounted) setState(() => _categories = cats);
    } catch (_) {
      // The product list still works without the filter chips.
    }
  }

  Future<void> _reload() async {
    setState(() {
      _loading = true;
      _error = null;
      _page = 1;
    });
    await _fetch(reset: true);
  }

  Future<void> _fetch({bool reset = false}) async {
    try {
      final page = await apiOf(
        context,
      ).products(page: _page, category: _category, search: _search.text.trim());
      if (!mounted) return;
      setState(() {
        if (reset) _products.clear();
        _products.addAll(page.items);
        _hasMore = page.hasMore;
        _loading = false;
      });
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = errorText(e);
          _loading = false;
        });
      }
    }
  }

  void _onSearch(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), _reload);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Shop'), actions: const [CartButton()]),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
            child: TextField(
              controller: _search,
              onChanged: _onSearch,
              textInputAction: TextInputAction.search,
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search),
                hintText: 'Search cards and stands',
                isDense: true,
              ),
            ),
          ),
          if (_categories.isNotEmpty)
            SizedBox(
              height: 44,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 16),
                children: [
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: const Text('All'),
                      selected: _category == null,
                      onSelected: (_) => _pick(null),
                    ),
                  ),
                  for (final c in _categories)
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ChoiceChip(
                        label: Text(c.name),
                        selected: _category == c.slug,
                        onSelected: (_) => _pick(c.slug),
                      ),
                    ),
                ],
              ),
            ),
          Expanded(child: _body()),
        ],
      ),
    );
  }

  void _pick(String? slug) {
    setState(() => _category = slug);
    _reload();
  }

  Widget _body() {
    if (_loading && _products.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && _products.isEmpty) {
      return ErrorView(message: _error!, onRetry: _reload);
    }
    return RefreshIndicator(
      onRefresh: _reload,
      child: _products.isEmpty
          ? const PageList(
              children: [
                EmptyState(icon: Icons.search_off, title: 'No products found'),
              ],
            )
          : CustomScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              slivers: [
                SliverPadding(
                  padding: const EdgeInsets.all(16),
                  sliver: SliverGrid.builder(
                    gridDelegate:
                        const SliverGridDelegateWithMaxCrossAxisExtent(
                          maxCrossAxisExtent: 240,
                          mainAxisSpacing: 12,
                          crossAxisSpacing: 12,
                          childAspectRatio: 0.68,
                        ),
                    itemCount: _products.length,
                    itemBuilder: (context, i) =>
                        ProductCard(product: _products[i]),
                  ),
                ),
                if (_hasMore)
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                      child: BusyButton(
                        label: 'Load more',
                        outlined: true,
                        onPressed: () async {
                          _page++;
                          await _fetch();
                        },
                      ),
                    ),
                  ),
              ],
            ),
    );
  }
}

class ProductCard extends StatelessWidget {
  const ProductCard({super.key, required this.product});
  final Product product;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => ProductScreen(slug: product.slug, initial: product),
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              child: Hero(
                tag: 'product-${product.id}',
                child: ProductImage(product.imageUrl),
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    product.name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleSmall,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    money(product.priceMinor, product.currency),
                    style: theme.textTheme.titleMedium?.copyWith(
                      color: theme.colorScheme.primary,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The cart icon with a count, for app bars.
class CartButton extends StatelessWidget {
  const CartButton({super.key});

  @override
  Widget build(BuildContext context) {
    final count = context.select<Cart, int>((c) => c.count);
    return IconButton(
      tooltip: 'Cart',
      onPressed: () {
        ScaffoldMessenger.of(context).hideCurrentSnackBar();
        Navigator.of(context)
            .push(MaterialPageRoute(builder: (_) => const CartScreen()));
      },
      icon: Badge(
        isLabelVisible: count > 0,
        label: Text('$count'),
        child: const Icon(Icons.shopping_bag_outlined),
      ),
    );
  }
}
