import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../core/app_exception.dart';
import '../core/flavor_config.dart';

/// Тонкая обёртка над HTTP-запросами к бэкенду ServiceBox.
///
/// Всегда подставляет X-Shop-ID из зашитого в сборку api_key магазина —
/// это единственный способ авторизации у widget-API (см. TenantContext
/// middleware на бэкенде). Код магазина (shop_code) в запросах не участвует.
class ApiClient {
  static const _timeout = Duration(seconds: 15);

  Uri _uri(String path, [Map<String, String>? query]) {
    return Uri.parse('${FlavorConfig.apiBaseUrl}/api$path')
        .replace(queryParameters: query);
  }

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Shop-ID': FlavorConfig.shopApiKey,
      };

  Future<Map<String, dynamic>> get(String path, {Map<String, String>? query}) {
    return _send(() => http.get(_uri(path, query), headers: _headers));
  }

  Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body) {
    return _send(() => http.post(_uri(path), headers: _headers, body: jsonEncode(body)));
  }

  Future<Map<String, dynamic>> _send(Future<http.Response> Function() request) async {
    final http.Response response;
    try {
      response = await request().timeout(_timeout);
    } on TimeoutException {
      throw AppException.network();
    } on SocketException {
      throw AppException.network();
    } catch (_) {
      throw AppException.network();
    }

    if (response.statusCode == 404) throw AppException.shopNotFound();
    if (response.statusCode >= 500) throw AppException.server();
    if (response.statusCode >= 400) throw AppException.badResponse();

    if (response.body.isEmpty) return {};
    try {
      return jsonDecode(utf8.decode(response.bodyBytes)) as Map<String, dynamic>;
    } catch (_) {
      throw AppException.badResponse();
    }
  }
}
