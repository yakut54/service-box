import '../models/auth_session.dart';
import 'api_auth_repository.dart';

/// Замаскированный телефон для показа ("7900***33") + код из ответа сервера,
/// пока нет реальной отправки SMS (devCode) — показывается подсказкой в
/// OtpVerifyScreen до отдельного распоряжения. Как только подключат SMS,
/// бэкенд перестанет присылать devCode, и подсказка сама пропадёт.
class PhoneCodeRequest {
  final String? maskedPhone;
  final String? devCode;

  const PhoneCodeRequest({this.maskedPhone, this.devCode});
}

/// Вход по телефону: запросить SMS-код → подтвердить код → получить
/// долгую сессию (см. PLAN.md, М2 — /widget/phone/request-code, /verify).
abstract class AuthRepository {
  Future<PhoneCodeRequest> requestCode(String phone);

  Future<AuthSession> verifyCode(String phone, String code);

  factory AuthRepository.create() => ApiAuthRepository();
}
