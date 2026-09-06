import '../models/profile.dart';
import 'api_profile_repository.dart';

/// Профиль байера — доступен только по долгой сессии входа
/// (см. X-Phone-Session, AuthState.session).
abstract class ProfileRepository {
  Future<Profile> fetch(String sessionToken);

  Future<Profile> updateName(String sessionToken, String name);

  Future<Profile> uploadAvatar(
    String sessionToken,
    List<int> bytes,
    String filename,
  );

  Future<Profile> deleteAvatar(String sessionToken);

  /// Регистрация Firebase device token для push (см. FcmService — пока не
  /// подключён к main.dart, ждёт google-services.json от шопера).
  Future<void> updateFcmToken(String sessionToken, String fcmToken);

  /// Настройки категорий push (поведенческие / промо). Транзакционные —
  /// не отключаются, здесь их нет.
  Future<NotificationPrefs> fetchNotificationPrefs(String sessionToken);

  Future<NotificationPrefs> updateNotificationPrefs(
    String sessionToken,
    NotificationPrefs prefs,
  );

  factory ProfileRepository.create() => ApiProfileRepository();
}
