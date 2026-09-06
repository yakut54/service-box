import '../core/app_exception.dart';
import '../models/profile.dart';
import 'api_client.dart';
import 'profile_repository.dart';

class ApiProfileRepository implements ProfileRepository {
  final ApiClient _client = ApiClient();

  Map<String, String> _authHeaders(String sessionToken) => {
    'X-Phone-Session': sessionToken,
  };

  @override
  Future<Profile> fetch(String sessionToken) async {
    final json = await _client.get(
      '/widget/profile',
      headers: _authHeaders(sessionToken),
    );
    final data = json['data'] as Map<String, dynamic>?;
    if (data == null) throw AppException.badResponse();
    return Profile.fromJson(data);
  }

  @override
  Future<Profile> updateName(String sessionToken, String name) async {
    final json = await _client.put('/widget/profile', {
      'name': name,
    }, headers: _authHeaders(sessionToken));
    final data = json['data'] as Map<String, dynamic>?;
    if (data == null) throw AppException.badResponse();
    return Profile.fromJson(data);
  }

  @override
  Future<Profile> uploadAvatar(
    String sessionToken,
    List<int> bytes,
    String filename,
  ) async {
    final json = await _client.uploadBytes(
      '/widget/profile/avatar',
      'avatar',
      bytes,
      filename,
      headers: _authHeaders(sessionToken),
    );
    final data = json['data'] as Map<String, dynamic>?;
    if (data == null) throw AppException.badResponse();
    return Profile.fromJson(data);
  }

  @override
  Future<Profile> deleteAvatar(String sessionToken) async {
    final json = await _client.delete(
      '/widget/profile/avatar',
      headers: _authHeaders(sessionToken),
    );
    final data = json['data'] as Map<String, dynamic>?;
    if (data == null) throw AppException.badResponse();
    return Profile.fromJson(data);
  }

  @override
  Future<void> updateFcmToken(String sessionToken, String fcmToken) async {
    await _client.post(
      '/widget/profile/fcm-token',
      {'fcm_token': fcmToken},
      headers: _authHeaders(sessionToken),
    );
  }

  @override
  Future<NotificationPrefs> fetchNotificationPrefs(String sessionToken) async {
    final json = await _client.get(
      '/widget/profile/notification-prefs',
      headers: _authHeaders(sessionToken),
    );
    final data = json['data'] as Map<String, dynamic>?;
    if (data == null) throw AppException.badResponse();
    return NotificationPrefs.fromJson(data);
  }

  @override
  Future<NotificationPrefs> updateNotificationPrefs(
    String sessionToken,
    NotificationPrefs prefs,
  ) async {
    final json = await _client.put(
      '/widget/profile/notification-prefs',
      prefs.toJson(),
      headers: _authHeaders(sessionToken),
    );
    final data = json['data'] as Map<String, dynamic>?;
    if (data == null) throw AppException.badResponse();
    return NotificationPrefs.fromJson(data);
  }
}
