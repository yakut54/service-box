import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../models/auth_session.dart';

/// Хранит токен входа в зашифрованном хранилище (Android Keystore),
/// не в SharedPreferences — токен доступа не должен лежать открытым
/// текстом на диске (см. исследование best practice перед М2).
class AuthTokenStore {
  static const _storage = FlutterSecureStorage();
  static const _phoneKey = 'auth_phone';
  static const _tokenKey = 'auth_session_token';
  static const _expiresKey = 'auth_session_expires_at';

  Future<AuthSession?> load() async {
    final token = await _storage.read(key: _tokenKey);
    final phone = await _storage.read(key: _phoneKey);
    final expiresRaw = await _storage.read(key: _expiresKey);
    if (token == null || phone == null || expiresRaw == null) return null;

    final expiresAt = DateTime.tryParse(expiresRaw);
    if (expiresAt == null) return null;

    final session = AuthSession(
      phone: phone,
      sessionToken: token,
      expiresAt: expiresAt,
    );
    if (session.isExpired) {
      await clear();
      return null;
    }
    return session;
  }

  Future<void> save(AuthSession session) async {
    await _storage.write(key: _tokenKey, value: session.sessionToken);
    await _storage.write(key: _phoneKey, value: session.phone);
    await _storage.write(
      key: _expiresKey,
      value: session.expiresAt.toIso8601String(),
    );
  }

  Future<void> clear() async {
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _phoneKey);
    await _storage.delete(key: _expiresKey);
  }
}
