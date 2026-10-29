/// Build-time settings, passed with `--dart-define`.
///
/// The Android emulator reaches the host PC's localhost as 10.0.2.2, so that
/// is the default. A real phone on the same Wi-Fi needs the PC's LAN address,
/// for example `--dart-define=API_BASE_URL=http://192.168.1.20:8000`.
class AppConfig {
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000',
  );

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
