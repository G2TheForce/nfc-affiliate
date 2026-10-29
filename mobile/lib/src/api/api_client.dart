import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import '../config.dart';

/// A failed API call, carrying Laravel's `message` and per-field `errors`.
class ApiException implements Exception {
  ApiException(this.status, this.message, [this.fieldErrors = const {}]);

  /// HTTP status, or 0 when the server could not be reached.
  final int status;
  final String message;
  final Map<String, List<String>> fieldErrors;

  bool get isUnauthenticated => status == 401;
  bool get isNotFound => status == 404;

  /// The first message for [field], for showing under a form input.
  String? field(String field) {
    final list = fieldErrors[field];
    return (list == null || list.isEmpty) ? null : list.first;
  }

  /// The message a person should see: the first field error when there is
  /// one, otherwise the server's message.
  String get display {
    for (final list in fieldErrors.values) {
      if (list.isNotEmpty) return list.first;
    }
    return message;
  }

  @override
  String toString() => 'ApiException($status, $message)';
}

/// Thin JSON client for /api/v1 using a Sanctum bearer token.
class ApiClient {
  ApiClient({http.Client? httpClient, String? root})
    : _http = httpClient ?? http.Client(),
      _fixedRoot = root;

  final http.Client _http;
  final String? _fixedRoot;

  /// Follows the server address set in the app unless a root was given.
  String get _root => _fixedRoot ?? AppConfig.apiRoot;
  String? token;

  /// Called when a signed-in request gets 401 (token expired or revoked).
  void Function()? onUnauthenticated;

  static const _timeout = Duration(seconds: 30);

  Uri uri(String path, [Map<String, dynamic>? query]) {
    final q = <String, String>{};
    query?.forEach((key, value) {
      if (value != null && value.toString().isNotEmpty) {
        q[key] = value.toString();
      }
    });
    return Uri.parse('$_root/${path.replaceAll(RegExp(r'^/+'), '')}')
        .replace(queryParameters: q.isEmpty ? null : q);
  }

  Map<String, String> _headers({
    Map<String, String>? extra,
    bool json = false,
  }) => {
    HttpHeaders.acceptHeader: 'application/json',
    if (json) HttpHeaders.contentTypeHeader: 'application/json',
    if (token != null) HttpHeaders.authorizationHeader: 'Bearer $token',
    ...?extra,
  };

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
  }) => _send(() => _http.get(uri(path, query), headers: _headers()));

  Future<Map<String, dynamic>> post(
    String path, {
    Object? body,
    Map<String, String>? headers,
  }) => _send(
    () => _http.post(
      uri(path),
      headers: _headers(extra: headers, json: true),
      body: jsonEncode(body ?? const {}),
    ),
  );

  Future<Map<String, dynamic>> put(String path, {Object? body}) => _send(
    () => _http.put(
      uri(path),
      headers: _headers(json: true),
      body: jsonEncode(body ?? const {}),
    ),
  );

  Future<Map<String, dynamic>> patch(String path, {Object? body}) => _send(
    () => _http.patch(
      uri(path),
      headers: _headers(json: true),
      body: jsonEncode(body ?? const {}),
    ),
  );

  /// Downloads a file (for example a marketing material) with the token.
  Future<(Uint8List, String?)> getBytes(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    http.Response res;
    try {
      res = await _http
          .get(uri(path, query), headers: _headers())
          .timeout(const Duration(minutes: 2));
    } on TimeoutException {
      throw ApiException(0, 'The download took too long. Please try again.');
    } on SocketException {
      throw ApiException(
        0,
        'Could not reach the server. Check your connection.',
      );
    } on http.ClientException {
      throw ApiException(
        0,
        'Could not reach the server. Check your connection.',
      );
    }
    if (res.statusCode >= 200 && res.statusCode < 300) {
      return (res.bodyBytes, res.headers['content-type']);
    }
    if (res.statusCode == 401 && token != null) onUnauthenticated?.call();
    throw ApiException(res.statusCode, _message(res.statusCode, const {}));
  }

  /// Headers for loading a protected image directly, e.g. Image.network.
  Map<String, String> get authHeaders => _headers();

  Future<Map<String, dynamic>> _send(
    Future<http.Response> Function() request,
  ) async {
    final hadToken = token != null;
    http.Response res;
    try {
      res = await request().timeout(_timeout);
    } on TimeoutException {
      throw ApiException(
        0,
        'The server took too long to answer. Please try again.',
      );
    } on SocketException {
      throw ApiException(
        0,
        'Could not reach the server. Check your connection.',
      );
    } on http.ClientException {
      throw ApiException(
        0,
        'Could not reach the server. Check your connection.',
      );
    }

    Map<String, dynamic> body = const {};
    if (res.body.isNotEmpty) {
      try {
        final decoded = jsonDecode(res.body);
        if (decoded is Map<String, dynamic>) body = decoded;
      } on FormatException {
        // A non-JSON answer (for example a proxy error page) falls through.
      }
    }

    if (res.statusCode >= 200 && res.statusCode < 300) return body;

    if (res.statusCode == 401 && hadToken) onUnauthenticated?.call();
    throw ApiException(
      res.statusCode,
      _message(res.statusCode, body),
      _errors(body),
    );
  }

  static String _message(int status, Map<String, dynamic> body) {
    final m = body['message'];
    if (m is String && m.isNotEmpty && status != 500) return m;
    return switch (status) {
      401 => 'Please sign in again.',
      403 => 'You do not have access to this.',
      404 => 'Not found.',
      429 => 'Too many attempts. Please wait a minute and try again.',
      _ => 'Something went wrong on the server. Please try again.',
    };
  }

  static Map<String, List<String>> _errors(Map<String, dynamic> body) {
    final raw = body['errors'];
    if (raw is! Map) return const {};
    return raw.map(
      (k, v) => MapEntry(
        k.toString(),
        v is List ? v.map((e) => e.toString()).toList() : [v.toString()],
      ),
    );
  }
}
