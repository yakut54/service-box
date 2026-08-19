/// Сессия входа по телефону — 60-дневный токен (см. CustomerSession
/// на бэкенде), не путать с 30-минутным токеном виджета.
class AuthSession {
  final String phone;
  final String sessionToken;
  final DateTime expiresAt;

  const AuthSession({
    required this.phone,
    required this.sessionToken,
    required this.expiresAt,
  });

  bool get isExpired => DateTime.now().isAfter(expiresAt);
}
