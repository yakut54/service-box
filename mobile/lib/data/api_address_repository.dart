import '../core/app_exception.dart';
import '../models/address.dart';
import 'api_client.dart';
import 'address_repository.dart';

class ApiAddressRepository implements AddressRepository {
  final ApiClient _client = ApiClient();

  Map<String, String> _authHeaders(String sessionToken) => {
    'X-Phone-Session': sessionToken,
  };

  @override
  Future<List<Address>> list(String sessionToken) async {
    final json = await _client.get(
      '/widget/addresses',
      headers: _authHeaders(sessionToken),
    );
    final list = json['data'] as List<dynamic>? ?? const [];
    return list
        .map((a) => Address.fromJson(a as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<Address> add(
    String sessionToken, {
    String? label,
    required String city,
    required String street,
    required String building,
    String? apartment,
    String? postalCode,
  }) async {
    final json = await _client.post('/widget/addresses', {
      if (label != null && label.isNotEmpty) 'label': label,
      'city': city,
      'street': street,
      'building': building,
      if (apartment != null && apartment.isNotEmpty) 'apartment': apartment,
      if (postalCode != null && postalCode.isNotEmpty)
        'postal_code': postalCode,
    }, headers: _authHeaders(sessionToken));

    final data = json['data'] as Map<String, dynamic>?;
    if (data == null) throw AppException.badResponse();
    return Address.fromJson(data);
  }

  @override
  Future<void> setDefault(String sessionToken, String addressId) {
    return _client.put(
      '/widget/addresses/$addressId/default',
      {},
      headers: _authHeaders(sessionToken),
    );
  }

  @override
  Future<void> delete(String sessionToken, String addressId) {
    return _client.delete(
      '/widget/addresses/$addressId',
      headers: _authHeaders(sessionToken),
    );
  }
}
