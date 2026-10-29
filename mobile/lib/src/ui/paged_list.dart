import 'package:flutter/material.dart';

import '../api/models.dart';
import 'widgets.dart';

/// A paginated list with pull-to-refresh and a "Load more" button.
class PagedList<T> extends StatefulWidget {
  const PagedList({
    super.key,
    required this.load,
    required this.itemBuilder,
    required this.empty,
    this.header,
  });

  final Future<Paginated<T>> Function(int page) load;
  final Widget Function(BuildContext context, T item) itemBuilder;
  final Widget empty;
  final Widget? header;

  @override
  State<PagedList<T>> createState() => PagedListState<T>();
}

class PagedListState<T> extends State<PagedList<T>> {
  final List<T> _items = [];
  int _page = 1;
  bool _hasMore = false;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    reload();
  }

  Future<void> reload() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await widget.load(1);
      if (!mounted) return;
      setState(() {
        _items
          ..clear()
          ..addAll(page.items);
        _page = 1;
        _hasMore = page.hasMore;
      });
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _more() async {
    try {
      final page = await widget.load(_page + 1);
      if (!mounted) return;
      setState(() {
        _items.addAll(page.items);
        _page = page.currentPage;
        _hasMore = page.hasMore;
      });
    } catch (e) {
      if (mounted) showSnack(context, errorText(e));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _items.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && _items.isEmpty) {
      return ErrorView(message: _error!, onRetry: reload);
    }
    return RefreshIndicator(
      onRefresh: reload,
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        itemCount: _items.length + 2,
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (context, i) {
          if (i == 0) {
            return widget.header ??
                (_items.isEmpty ? widget.empty : const SizedBox.shrink());
          }
          if (i == _items.length + 1) {
            if (widget.header != null && _items.isEmpty) return widget.empty;
            return _hasMore
                ? BusyButton(
                    label: 'Load more',
                    outlined: true,
                    onPressed: _more,
                  )
                : const SizedBox.shrink();
          }
          return widget.itemBuilder(context, _items[i - 1]);
        },
      ),
    );
  }
}
