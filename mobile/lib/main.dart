import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'src/api/api_client.dart';
import 'src/api/store_api.dart';
import 'src/app.dart';
import 'src/state/cart.dart';
import 'src/state/session.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final api = Api(ApiClient());
  final session = Session(api, SecureTokenStore());
  final cart = Cart();

  runApp(
    MultiProvider(
      providers: [
        Provider<Api>.value(value: api),
        ChangeNotifierProvider<Session>.value(value: session),
        ChangeNotifierProvider<Cart>.value(value: cart),
      ],
      child: const NfcApp(),
    ),
  );

  await Future.wait([session.restore(), cart.load()]);
}
