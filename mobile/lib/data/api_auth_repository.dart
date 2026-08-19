import '../core/app_exception.dart';
import '../models/auth_session.dart';
import 'api_client.dart';
import 'auth_repository.dart';

class ApiAuthRepository implements AuthRepository {
  final ApiClient _client = ApiClient();

  @override
  Future<String?> requestCode(String phone) async {
    final json = await _client.post('/widget/phone/request-code', {
      'phone': phone,
    });
    return json['masked_phone'] as String?;
  }

  @override
  Future<AuthSession> verifyCode(String phone, String code) async {
    final json = await _client.post('/widget/phone/verify', {
      'phone': phone,
      'code': code,
    });
    final sessionToken = json['session_token'] as String?;
    final expiresIn = (json['session_expires_in'] as num?)?.toInt();
    if (sessionToken == null || expiresIn == null) {
      throw AppException.badResponse();
    }

    return AuthSession(
      phone: phone,
      sessionToken: sessionToken,
      expiresAt: DateTime.now().add(Duration(seconds: expiresIn)),
    );
  }
}
