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
    return Uri.parse(
      '${FlavorConfig.apiBaseUrl}/api$path',
    ).replace(queryParameters: query);
  }

  Map<String, String> get _headers => {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Shop-ID': FlavorConfig.shopApiKey,
  };

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, String>? query,
    Map<String, String>? headers,
  }) {
    return _send(
      () => http.get(_uri(path, query), headers: {..._headers, ...?headers}),
    );
  }

  Future<Map<String, dynamic>> post(
    String path,
    Map<String, dynamic> body, {
    Map<String, String>? headers,
  }) {
    return _send(
      () => http.post(
        _uri(path),
        headers: {..._headers, ...?headers},
        body: jsonEncode(body),
      ),
    );
  }

  Future<Map<String, dynamic>> put(
    String path,
    Map<String, dynamic> body, {
    Map<String, String>? headers,
  }) {
    return _send(
      () => http.put(
        _uri(path),
        headers: {..._headers, ...?headers},
        body: jsonEncode(body),
      ),
    );
  }

  Future<Map<String, dynamic>> delete(
    String path, {
    Map<String, String>? headers,
  }) {
    return _send(
      () => http.delete(_uri(path), headers: {..._headers, ...?headers}),
    );
  }

  /// Загрузка файла (multipart/form-data) — для JSON-эндпоинтов используй
  /// post()/put(), этот метод только для байтов файла (например, аватарки).
  Future<Map<String, dynamic>> uploadBytes(
    String path,
    String fieldName,
    List<int> bytes,
    String filename, {
    Map<String, String>? headers,
  }) {
    return _send(() async {
      // Content-Type идёт своим — MultipartRequest сам проставляет
      // multipart/form-data с boundary, наш JSON-дефолт тут только помешает.
      final requestHeaders = {..._headers, ...?headers}
        ..remove('Content-Type');
      final request = http.MultipartRequest('POST', _uri(path))
        ..headers.addAll(requestHeaders)
        ..files.add(
          http.MultipartFile.fromBytes(fieldName, bytes, filename: filename),
        );
      final streamed = await request.send();
      return http.Response.fromStream(streamed);
    });
  }

  Future<Map<String, dynamic>> _send(
    Future<http.Response> Function() request,
  ) async {
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

    if (response.statusCode == 404) throw AppException.notFound();
    if (response.statusCode >= 500) throw AppException.server();
    if (response.statusCode >= 400) {
      // Бэкенд часто кладёт конкретный текст на русском в 'message'
      // (например "Товар «X» закончился на складе") — показываем его
      // как есть вместо общего "магазин настроен неправильно".
      throw AppException.badResponse(_tryExtractMessage(response));
    }

    if (response.body.isEmpty) return {};
    try {
      return jsonDecode(utf8.decode(response.bodyBytes))
          as Map<String, dynamic>;
    } catch (_) {
      throw AppException.badResponse();
    }
  }

  String? _tryExtractMessage(http.Response response) {
    if (response.body.isEmpty) return null;
    try {
      final decoded = jsonDecode(utf8.decode(response.bodyBytes));
      if (decoded is Map<String, dynamic>) {
        final message = decoded['message'];
        if (message is String && message.isNotEmpty) return message;
      }
    } catch (_) {
      // тело не JSON — просто нет уточняющего текста
    }
    return null;
  }
}
