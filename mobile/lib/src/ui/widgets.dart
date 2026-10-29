import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:share_plus/share_plus.dart';

import '../api/api_client.dart';
import '../api/store_api.dart';
import '../config.dart';
import 'theme.dart';

Api apiOf(BuildContext context) => context.read<Api>();

String errorText(Object error) => error is ApiException
    ? error.display
    : 'Something went wrong. Please try again.';

void showSnack(BuildContext context, String message) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message)));
}

Future<void> copyText(
  BuildContext context,
  String text, {
  String label = 'Copied',
}) async {
  await Clipboard.setData(ClipboardData(text: text));
  if (context.mounted) showSnack(context, label);
}

Future<void> shareText(String text, {String? subject}) =>
    SharePlus.instance.share(ShareParams(text: text, subject: subject));

/// Loads [load] and shows a spinner, an error with Retry, or [builder].
/// Pull down to reload.
class LoadView<T> extends StatefulWidget {
  const LoadView({super.key, required this.load, required this.builder});

  final Future<T> Function() load;
  final Widget Function(
    BuildContext context,
    T data,
    Future<void> Function() reload,
  )
  builder;

  @override
  State<LoadView<T>> createState() => LoadViewState<T>();
}

class LoadViewState<T> extends State<LoadView<T>> {
  late Future<T> _future = widget.load();

  Future<void> reload() async {
    final next = widget.load();
    setState(() => _future = next);
    try {
      await next;
    } catch (_) {
      // Shown by the builder below.
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<T>(
      future: _future,
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snap.hasError) {
          return ErrorView(message: errorText(snap.error!), onRetry: reload);
        }
        return RefreshIndicator(
          onRefresh: reload,
          child: widget.builder(context, snap.data as T, reload),
        );
      },
    );
  }
}

class ErrorView extends StatelessWidget {
  const ErrorView({super.key, required this.message, this.onRetry});
  final String message;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.cloud_off_outlined,
              size: 48,
              color: Theme.of(context).colorScheme.outline,
            ),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
            if (onRetry != null) ...[
              const SizedBox(height: 16),
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size(140, 44),
                ),
                onPressed: onRetry,
                icon: const Icon(Icons.refresh),
                label: const Text('Try again'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.icon,
    required this.title,
    this.body,
    this.action,
  });
  final IconData icon;
  final String title;
  final String? body;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 32, horizontal: 24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 44, color: theme.colorScheme.outline),
          const SizedBox(height: 12),
          Text(
            title,
            style: theme.textTheme.titleMedium,
            textAlign: TextAlign.center,
          ),
          if (body != null) ...[
            const SizedBox(height: 6),
            Text(
              body!,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
              textAlign: TextAlign.center,
            ),
          ],
          if (action != null) ...[const SizedBox(height: 16), action!],
        ],
      ),
    );
  }
}

/// A list that always scrolls, so pull-to-refresh works even when short.
class PageList extends StatelessWidget {
  const PageList({
    super.key,
    required this.children,
    this.padding = const EdgeInsets.all(16),
  });
  final List<Widget> children;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) => ListView(
    physics: const AlwaysScrollableScrollPhysics(),
    padding: padding,
    children: children,
  );
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key, this.trailing});
  final String text;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 20, bottom: 8),
    child: Row(
      children: [
        Expanded(
          child: Text(
            text,
            style: Theme.of(context).textTheme.titleMedium
                ?.copyWith(fontWeight: FontWeight.w600),
          ),
        ),
        ?trailing,
      ],
    ),
  );
}

class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.label,
    required this.value,
    this.hint,
    this.highlight = false,
  });
  final String label;
  final String value;
  final String? hint;
  final bool highlight;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Card(
      color: highlight ? scheme.primary : null,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label,
              style: theme.textTheme.labelMedium?.copyWith(
                color: highlight ? scheme.onPrimary : scheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              value,
              style: theme.textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w700,
                color: highlight ? scheme.onPrimary : null,
              ),
            ),
            if (hint != null) ...[
              const SizedBox(height: 2),
              Text(
                hint!,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: highlight ? scheme.onPrimary : scheme.onSurfaceVariant,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Two tiles per row.
class StatGrid extends StatelessWidget {
  const StatGrid({super.key, required this.tiles});
  final List<Widget> tiles;

  @override
  Widget build(BuildContext context) {
    final rows = <Widget>[];
    for (var i = 0; i < tiles.length; i += 2) {
      rows.add(
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(child: tiles[i]),
                const SizedBox(width: 10),
                Expanded(
                  child: i + 1 < tiles.length ? tiles[i + 1] : const SizedBox(),
                ),
              ],
            ),
          ),
        ),
      );
    }
    return Column(children: rows);
  }
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.label, {super.key, required this.status});
  final String label;
  final String status;

  static Color colorFor(String status, ColorScheme scheme) => switch (status) {
    'paid' ||
    'available' ||
    'approved' ||
    'delivered' ||
    'completed' => const Color(0xFF15803D),
    'pending' ||
    'pending_payment' ||
    'requested' ||
    'processing' ||
    'shipped' => const Color(0xFFB45309),
    'reversed' ||
    'rejected' ||
    'failed' ||
    'cancelled' ||
    'refunded' ||
    'suspended' => const Color(0xFFB91C1C),
    _ => scheme.outline,
  };

  @override
  Widget build(BuildContext context) {
    final color = colorFor(status, Theme.of(context).colorScheme);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(99),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: color,
          fontSize: 12,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

/// A link with copy, share and QR buttons.
class LinkBox extends StatelessWidget {
  const LinkBox({super.key, required this.url, this.shareMessage, this.title});
  final String url;
  final String? shareMessage;
  final String? title;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 6, 6),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (title != null) Text(title!, style: theme.textTheme.labelLarge),
            SelectableText(
              url,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.primary,
              ),
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                IconButton(
                  tooltip: 'Copy',
                  icon: const Icon(Icons.copy_rounded),
                  onPressed: () => copyText(context, url, label: 'Link copied'),
                ),
                IconButton(
                  tooltip: 'QR code',
                  icon: const Icon(Icons.qr_code_2_rounded),
                  onPressed: () => showQr(context, url, title: title),
                ),
                IconButton(
                  tooltip: 'Share',
                  icon: const Icon(Icons.share_rounded),
                  onPressed: () => shareText(
                    shareMessage == null ? url : '$shareMessage\n$url',
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

Future<void> showQr(BuildContext context, String url, {String? title}) =>
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title ?? 'Scan to open'),
        content: SizedBox(
          width: 240,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                color: Colors.white,
                padding: const EdgeInsets.all(8),
                child: QrImageView(
                  data: url,
                  size: 220,
                  backgroundColor: Colors.white,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                url,
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Close'),
          ),
        ],
      ),
    );

/// A product photo, or a placeholder when there is none.
class ProductImage extends StatelessWidget {
  const ProductImage(this.url, {super.key, this.size});
  final String? url;
  final double? size;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final placeholder = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            Brand.primary.withValues(alpha: 0.18),
            Brand.cyan.withValues(alpha: 0.18),
          ],
        ),
      ),
      child: Icon(
        Icons.contactless_outlined,
        color: scheme.primary,
        size: (size ?? 80) * 0.4,
      ),
    );
    if (url == null) return placeholder;
    return Image.network(
      AppConfig.resolveUrl(url!),
      width: size,
      height: size,
      fit: BoxFit.cover,
      errorBuilder: (_, _, _) => placeholder,
    );
  }
}

/// Disables a button and shows a spinner while [onPressed] runs.
class BusyButton extends StatefulWidget {
  const BusyButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.outlined = false,
  });
  final String label;
  final Future<void> Function()? onPressed;
  final IconData? icon;
  final bool outlined;

  @override
  State<BusyButton> createState() => _BusyButtonState();
}

class _BusyButtonState extends State<BusyButton> {
  bool _busy = false;

  Future<void> _run() async {
    setState(() => _busy = true);
    try {
      await widget.onPressed!();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final onPressed = (_busy || widget.onPressed == null) ? null : _run;
    final child = _busy
        ? const SizedBox(
            width: 20,
            height: 20,
            child: CircularProgressIndicator(strokeWidth: 2),
          )
        : Text(widget.label);
    if (widget.outlined) {
      return widget.icon == null
          ? OutlinedButton(onPressed: onPressed, child: child)
          : OutlinedButton.icon(
              onPressed: onPressed,
              icon: Icon(widget.icon),
              label: child,
            );
    }
    return widget.icon == null
        ? FilledButton(onPressed: onPressed, child: child)
        : FilledButton.icon(
            onPressed: onPressed,
            icon: Icon(widget.icon),
            label: child,
          );
  }
}
