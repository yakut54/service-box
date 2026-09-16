import 'api_client.dart';
import 'cart_activity_repository.dart';

class ApiCartActivityRepository implements CartActivityRepository {
  final ApiClient _client = ApiClient();

  Map<String, String> _authHeaders(String sessionToken) => {
    'X-Phone-Session': sessionToken,
  };

  @override
  Future<void> updateActivity(String sessionToken, int itemsCount) async {
    await _client.put(
      '/widget/cart/activity',
      {'items_count': itemsCount},
      headers: _authHeaders(sessionToken),
    );
  }
}
