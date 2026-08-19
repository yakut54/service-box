import '../models/auth_session.dart';
import 'api_auth_repository.dart';

/// Вход по телефону: запросить SMS-код → подтвердить код → получить
/// долгую сессию (см. PLAN.md, М2 — /widget/phone/request-code, /verify).
abstract class AuthRepository {
  /// Возвращает замаскированный телефон для показа ("7900***33").
  Future<String?> requestCode(String phone);

  Future<AuthSession> verifyCode(String phone, String code);

  factory AuthRepository.create() => ApiAuthRepository();
}
