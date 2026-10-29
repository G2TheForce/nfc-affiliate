import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../api/store_api.dart';

/// Where the bearer token lives between app starts.
abstract class TokenStore {
  Future<String?> read();
  Future<void> write(String token);
  Future<void> clear();
}

class SecureTokenStore implements TokenStore {
  static const _key = 'api_token';
  final _storage = const FlutterSecureStorage();

  @override
  Future<String?> read() => _storage.read(key: _key);
  @override
  Future<void> write(String token) => _storage.write(key: _key, value: token);
  @override
  Future<void> clear() => _storage.delete(key: _key);
}

/// Who is signed in. Tokens last 30 days on the server and are reused across
/// app starts, so the app only signs in again after a 401.
class Session extends ChangeNotifier {
  Session(this.api, this._tokens) {
    api.client.onUnauthenticated = _expired;
  }

  final Api api;
  final TokenStore _tokens;

  User? _user;
  bool _restoring = true;

  User? get user => _user;
  bool get signedIn => _user != null;
  bool get restoring => _restoring;

  /// Loads the saved token, if any, and checks it is still valid.
  Future<void> restore() async {
    try {
      final token = await _tokens.read();
      if (token != null) {
        api.client.token = token;
        try {
          _user = await api.me();
        } on ApiException catch (e) {
          if (e.isUnauthenticated) {
            await _forget();
          } else {
            // Offline: keep the saved token and try again on the next start.
            api.client.token = null;
          }
        }
      }
    } finally {
      _restoring = false;
      notifyListeners();
    }
  }

  Future<void> login(String email, String password) async {
    final (token, user) = await api.login(email.trim(), password, deviceName);
    await _signedIn(token, user);
  }

  Future<void> register({
    required String name,
    required String email,
    String? phone,
    required String password,
  }) async {
    final (token, user) = await api.register(
      name: name.trim(),
      email: email.trim(),
      phone: phone?.trim(),
      password: password,
      deviceName: deviceName,
    );
    await _signedIn(token, user);
  }

  Future<void> refreshUser() async {
    if (!signedIn) return;
    _user = await api.me();
    notifyListeners();
  }

  void replaceUser(User user) {
    _user = user;
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      await api.logout();
    } on ApiException {
      // The token is dropped locally either way.
    }
    await _forget();
    notifyListeners();
  }

  Future<void> _signedIn(String token, User user) async {
    api.client.token = token;
    await _tokens.write(token);
    _user = user;
    notifyListeners();
  }

  Future<void> _forget() async {
    api.client.token = null;
    _user = null;
    await _tokens.clear();
  }

  void _expired() {
    if (_user == null && api.client.token == null) return;
    _forget().then((_) => notifyListeners());
  }

  /// Shown on the server's list of signed-in devices.
  static String get deviceName {
    if (kIsWeb) return 'web-app';
    try {
      return '${Platform.operatingSystem}-app';
    } catch (_) {
      return 'mobile-app';
    }
  }
}
