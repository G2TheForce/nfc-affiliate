import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../../api/format.dart';
import '../../api/models.dart';
import '../../ui/widgets.dart';

/// Pays for a placed order with eSewa or Khalti, then waits for the server
/// to confirm it. Only the server's status check counts: what the provider
/// page shows is never taken as proof (docs/api-guide.md section 8).
class PaymentScreen extends StatefulWidget {
  const PaymentScreen({super.key, required this.order, this.accessToken});
  final Order order;

  /// Guest orders need the one-time token from checkout.
  final String? accessToken;

  @override
  State<PaymentScreen> createState() => _PaymentScreenState();
}

enum _Stage { choose, checking, paid, failed, pending }

class _PaymentScreenState extends State<PaymentScreen> {
  late final Future<List<PaymentMethod>> _methods = apiOf(context)
      .paymentMethods();
  _Stage _stage = _Stage.choose;
  String? _error;
  Timer? _poll;

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _pay(PaymentMethod method) async {
    setState(() => _error = null);
    try {
      final redirect = await apiOf(context)
          .startPayment(widget.order.id, method.key, widget.accessToken);
      if (!mounted) return;
      final returned = await Navigator.of(context).push<bool>(
        MaterialPageRoute(
          fullscreenDialog: true,
          builder: (_) =>
              ProviderWebView(redirect: redirect, title: method.label),
        ),
      );
      if (!mounted) return;
      if (returned == true) {
        _startPolling();
      } else {
        await _checkOnce();
      }
    } catch (e) {
      setState(() => _error = errorText(e));
    }
  }

  /// The payment page was closed before the provider sent the customer back.
  Future<void> _checkOnce() async {
    try {
      final status = await apiOf(context)
          .paymentStatus(widget.order.id, widget.accessToken);
      if (!mounted) return;
      if (status.paid) {
        setState(() => _stage = _Stage.paid);
      } else {
        setState(
          () => _error = 'The payment page was closed. If you paid, tap Check payment; otherwise choose a way to pay.',
        );
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    }
  }

  /// Polls every 4 seconds for up to 2 minutes, as the API guide suggests.
  void _startPolling() {
    _poll?.cancel();
    setState(() => _stage = _Stage.checking);
    var attempts = 0;
    Future<void> tick() async {
      attempts++;
      try {
        final status = await apiOf(context)
            .paymentStatus(widget.order.id, widget.accessToken);
        if (!mounted) return;
        if (status.paid) {
          _poll?.cancel();
          setState(() => _stage = _Stage.paid);
        } else if (status.failed) {
          _poll?.cancel();
          setState(() => _stage = _Stage.failed);
        } else if (attempts >= 30) {
          _poll?.cancel();
          setState(() => _stage = _Stage.pending);
        }
      } catch (_) {
        // Keep polling through brief network drops.
        if (attempts >= 30 && mounted) {
          _poll?.cancel();
          setState(() => _stage = _Stage.pending);
        }
      }
    }

    tick();
    _poll = Timer.periodic(const Duration(seconds: 4), (_) => tick());
  }

  @override
  Widget build(BuildContext context) {
    final order = widget.order;
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Payment')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Order ${order.number}',
                    style: theme.textTheme.titleMedium,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    money(order.totalMinor, order.currency),
                    style: theme.textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '${order.items.length} item${order.items.length == 1 ? '' : 's'} to ${order.shippingCity}',
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 20),
          ...switch (_stage) {
            _Stage.choose => _choose(context),
            _Stage.checking => [
              const Center(child: CircularProgressIndicator()),
              const SizedBox(height: 16),
              const Text(
                'Checking your payment with the provider...',
                textAlign: TextAlign.center,
              ),
            ],
            _Stage.paid => [
              const EmptyState(
                icon: Icons.check_circle_outline,
                title: 'Payment received',
                body: 'Thank you. We will let you know when your order ships.',
              ),
              FilledButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Done'),
              ),
            ],
            _Stage.failed => [
              const EmptyState(
                icon: Icons.error_outline,
                title: 'The payment did not go through',
                body: 'No money was taken. You can try again.',
              ),
              FilledButton(
                onPressed: () => setState(() => _stage = _Stage.choose),
                child: const Text('Try again'),
              ),
            ],
            _Stage.pending => [
              const EmptyState(
                icon: Icons.hourglass_empty,
                title: 'Still waiting for confirmation',
                body: 'If you completed the payment, it will be confirmed shortly. You can check the order under Account > My orders.',
              ),
              OutlinedButton(
                onPressed: _startPolling,
                child: const Text('Check again'),
              ),
              const SizedBox(height: 8),
              TextButton(
                onPressed: () => setState(() => _stage = _Stage.choose),
                child: const Text('Pay another way'),
              ),
            ],
          },
        ],
      ),
    );
  }

  List<Widget> _choose(BuildContext context) => [
    Text(
      'How would you like to pay?',
      style: Theme.of(context).textTheme.titleMedium,
    ),
    const SizedBox(height: 12),
    FutureBuilder<List<PaymentMethod>>(
      future: _methods,
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snap.hasError) return Text(errorText(snap.error!));
        final methods = snap.data!;
        if (methods.isEmpty) {
          return const EmptyState(
            icon: Icons.support_agent,
            title: 'Online payment is not available yet',
            body: 'Your order is saved. Our team will contact you to arrange bank transfer or cash on delivery.',
          );
        }
        return Column(
          children: [
            for (final m in methods)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: BusyButton(
                  label: 'Pay with ${m.label}',
                  icon: Icons.account_balance_wallet_outlined,
                  onPressed: widget.order.totalMinor < m.minimumMinor
                      ? null
                      : () => _pay(m),
                ),
              ),
          ],
        );
      },
    ),
    if (_error != null) ...[
      const SizedBox(height: 8),
      Text(
        _error!,
        style: TextStyle(color: Theme.of(context).colorScheme.error),
      ),
      const SizedBox(height: 8),
      OutlinedButton(
        onPressed: _startPolling,
        child: const Text('Check payment'),
      ),
    ],
  ];
}

/// Shows the provider's payment page. Pops `true` when the provider sends
/// the browser back to the store's /order-confirmed page.
class ProviderWebView extends StatefulWidget {
  const ProviderWebView({
    super.key,
    required this.redirect,
    required this.title,
  });

  /// `{type: "form"|"redirect", url, fields?}` from the start-payment call.
  final Map<String, dynamic> redirect;
  final String title;

  @override
  State<ProviderWebView> createState() => _ProviderWebViewState();
}

class _ProviderWebViewState extends State<ProviderWebView> {
  late final WebViewController _controller;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageStarted: (_) =>
              mounted ? setState(() => _loading = true) : null,
          onPageFinished: (_) =>
              mounted ? setState(() => _loading = false) : null,
          onNavigationRequest: (request) {
            if (isReturnUrl(request.url)) {
              _finish();
              return NavigationDecision.prevent;
            }
            return NavigationDecision.navigate;
          },
          // Some WebView versions report server redirects only here.
          onUrlChange: (change) {
            if (change.url != null && isReturnUrl(change.url!)) _finish();
          },
        ),
      );

    final url = widget.redirect['url'] as String;
    if (widget.redirect['type'] == 'form') {
      _controller.loadHtmlString(
        autoSubmitForm(
          url,
          (widget.redirect['fields'] as Map?)?.cast<String, dynamic>() ??
              const {},
        ),
      );
    } else {
      _controller.loadRequest(Uri.parse(url));
    }
  }

  bool _done = false;

  void _finish() {
    if (_done || !mounted) return;
    _done = true;
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title),
        leading: IconButton(
          icon: const Icon(Icons.close),
          tooltip: 'Close',
          onPressed: () => Navigator.of(context).pop(false),
        ),
        bottom: _loading
            ? const PreferredSize(
                preferredSize: Size.fromHeight(2),
                child: LinearProgressIndicator(minHeight: 2),
              )
            : null,
      ),
      body: WebViewWidget(controller: _controller),
    );
  }
}

/// The page the server redirects to after talking to the provider.
bool isReturnUrl(String url) => Uri.tryParse(url)?.path == '/order-confirmed';

/// eSewa takes a signed form POST, so the page builds one and submits it.
String autoSubmitForm(String action, Map<String, dynamic> fields) {
  const esc = HtmlEscape(HtmlEscapeMode.attribute);
  final inputs = fields.entries
      .map(
        (e) =>
            '<input type="hidden" name="${esc.convert(e.key)}" value="${esc.convert('${e.value}')}">',
      )
      .join();
  return '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      '<body onload="document.forms[0].submit()" style="font-family:sans-serif;text-align:center;padding-top:40vh">'
      '<form method="POST" action="${esc.convert(action)}">$inputs<noscript><button type="submit">Continue to payment</button></noscript></form>'
      'Opening the payment page...</body></html>';
}
