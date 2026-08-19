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

  factory ProfileRepository.create() => ApiProfileRepository();
}
