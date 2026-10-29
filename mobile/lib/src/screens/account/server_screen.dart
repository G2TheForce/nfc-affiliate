import 'dart:async';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';

import '../../config.dart';
import '../../state/cart.dart';
import '../../state/session.dart';
import '../../ui/widgets.dart';

/// Lets a tester point the app at another backend, such as their PC's
/// address on the current Wi-Fi.
class ServerScreen extends StatefulWidget {
  const ServerScreen({super.key});

  @override
  State<ServerScreen> createState() => _ServerScreenState();
}

class _ServerScreenState extends State<ServerScreen> {
  late final _address = TextEditingController(text: AppConfig.apiBaseUrl);
  String? _error;
  String? _result;

  @override
  void dispose() {
    _address.dispose();
    super.dispose();
  }

  String? _parse() {
    final url = AppConfig.normaliseBaseUrl(_address.text);
    setState(() {
      _error = url == null
          ? 'Enter an address such as http://192.168.1.20:8000'
          : null;
      _result = null;
    });
    return url;
  }

  Future<void> _test() async {
    final url = _parse();
    if (url == null) return;
    try {
      final res = await http
          .get(
            Uri.parse('$url/api/v1/store/categories'),
            headers: {'Accept': 'application/json'},
          )
          .timeout(const Duration(seconds: 8));
      setState(
        () => _result = res.statusCode == 200
            ? 'Connected. The store answered.'
            : 'The server answered with error ${res.statusCode}.',
      );
    } catch (_) {
      setState(
        () => _result =
            'Could not reach $url. Check the address, that the phone is on '
            'the same Wi-Fi, and that the backend was started with '
            '--host=0.0.0.0.',
      );
    }
  }

  Future<void> _save(String? url) async {
    final session = context.read<Session>();
    final cart = context.read<Cart>();
    final changed =
        (url ?? AppConfig.defaultApiBaseUrl) != AppConfig.apiBaseUrl;
    if (!changed) {
      Navigator.of(context).pop();
      return;
    }
    // Sign-ins and cart items belong to the old server.
    await AppConfig.setApiBaseUrl(url);
    cart.clear();
    await session.forget();
    if (mounted) {
      showSnack(context, 'Server changed. Sign in again if you need to.');
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Server address')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            'For testing against your own PC: start the backend with '
            '"php artisan serve --host=0.0.0.0 --port=8000", find the PC\'s '
            'IPv4 address with "ipconfig", and enter it here with :8000.',
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _address,
            keyboardType: TextInputType.url,
            autocorrect: false,
            decoration: InputDecoration(
              labelText: 'Server address',
              hintText: 'http://192.168.1.20:8000',
              errorText: _error,
              helperText: 'Built-in: ${AppConfig.defaultApiBaseUrl}',
            ),
          ),
          if (_result != null) ...[
            const SizedBox(height: 12),
            Text(_result!, style: theme.textTheme.bodyMedium),
          ],
          const SizedBox(height: 16),
          BusyButton(
            label: 'Test connection',
            outlined: true,
            onPressed: _test,
          ),
          const SizedBox(height: 10),
          BusyButton(
            label: 'Save',
            onPressed: () async {
              final url = _parse();
              if (url != null) await _save(url);
            },
          ),
          const SizedBox(height: 10),
          TextButton(
            onPressed: () => _save(null),
            child: const Text('Use the built-in address'),
          ),
        ],
      ),
    );
  }
}
