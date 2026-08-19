import '../models/address.dart';
import 'api_address_repository.dart';

/// Сохранённые адреса доставки — доступны только по долгой сессии входа
/// (см. X-Phone-Session, AuthState.session).
abstract class AddressRepository {
  Future<List<Address>> list(String sessionToken);

  Future<Address> add(
    String sessionToken, {
    String? label,
    required String city,
    required String street,
    required String building,
    String? apartment,
    String? postalCode,
  });

  Future<void> setDefault(String sessionToken, String addressId);

  Future<void> delete(String sessionToken, String addressId);

  factory AddressRepository.create() => ApiAddressRepository();
}
