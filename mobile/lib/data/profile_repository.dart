import '../models/profile.dart';
import 'api_profile_repository.dart';

/// Профиль байера — доступен только по долгой сессии входа
/// (см. X-Phone-Session, AuthState.session).
abstract class ProfileRepository {
  Future<Profile> fetch(String sessionToken);

  Future<Profile> updateName(String sessionToken, String name);

  factory ProfileRepository.create() => ApiProfileRepository();
}
