import 'package:shared_preferences/shared_preferences.dart';

/// App settings. The server address comes from `--dart-define` at build time
/// and can be changed in the app (Account > Server address), so one test APK
/// works on any Wi-Fi.
///
/// The Android emulator reaches the host PC's localhost as 10.0.2.2, so that
/// is the default. A real phone on the same Wi-Fi needs the PC's LAN address,
/// for example `--dart-define=API_BASE_URL=http://192.168.1.20:8000`.
class AppConfig {
  static const String defaultApiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000',
  );

  static const _prefsKey = 'api_base_url';
  static String? _override;

  static String get apiBaseUrl => _override ?? defaultApiBaseUrl;

  /// Reads the address saved in the app, if any. Call before the first request.
  static Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();
    _override = prefs.getString(_prefsKey);
  }

  /// Saves [url] as the server address, or goes back to the built-in one
  /// when it is null.
  static Future<void> setApiBaseUrl(String? url) async {
    final prefs = await SharedPreferences.getInstance();
    if (url == null || url == defaultApiBaseUrl) {
      _override = null;
      await prefs.remove(_prefsKey);
    } else {
      _override = url;
      await prefs.setString(_prefsKey, url);
    }
  }

  /// Turns what a person typed ("192.168.1.20:8000") into a base URL, or
  /// returns null when it is not a usable http(s) address.
  static String? normaliseBaseUrl(String input) {
    var text = input.trim();
    if (text.isEmpty) return null;
    if (!text.contains('://')) text = 'http://$text';
    final uri = Uri.tryParse(text);
    if (uri == null ||
        !(uri.scheme == 'http' || uri.scheme == 'https') ||
        uri.host.isEmpty) {
      return null;
    }
    return Uri(
      scheme: uri.scheme,
      host: uri.host,
      port: uri.hasPort ? uri.port : null,
      path: uri.path.replaceAll(RegExp(r'/+$'), ''),
    ).toString();
  }

  static const String appName = 'NFC Card Store';

  static String get apiRoot =>
      '${apiBaseUrl.replaceAll(RegExp(r'/+$'), '')}/api/v1';

  /// The server builds absolute URLs from its APP_URL (usually
  /// http://localhost:8000 locally). On the emulator that host is the phone
  /// itself, so links and images are pointed back at [apiBaseUrl].
  static String resolveUrl(String url) {
    final uri = Uri.tryParse(url);
    if (uri == null) return url;
    if (!uri.hasScheme) {
      return '${apiBaseUrl.replaceAll(RegExp(r'/+$'), '')}/${url.replaceAll(RegExp(r'^/+'), '')}';
    }
    const localHosts = {'localhost', '127.0.0.1'};
    if (localHosts.contains(uri.host)) {
      final base = Uri.parse(apiBaseUrl);
      return uri
          .replace(
            scheme: base.scheme,
            host: base.host,
            port: base.hasPort ? base.port : null,
          )
          .toString();
    }
    return url;
  }
}
