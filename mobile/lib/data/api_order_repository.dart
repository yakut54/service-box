import '../core/app_exception.dart';
import '../models/cart_item.dart';
import '../models/order.dart';
import 'api_client.dart';
import 'order_repository.dart';

class ApiOrderRepository implements OrderRepository {
  final ApiClient _client = ApiClient();

  @override
  Future<Order> createOrder({
    required String name,
    String? email,
    required String phone,
    required List<CartItem> items,
    String? discountCode,
  }) async {
    final json = await _client.post('/widget/orders', {
      'items': items
          .map(
            (item) => {
              'product_id': item.product.id,
              if (item.weightGrams != null)
                'weight_grams': item.weightGrams
              else
                'quantity': item.quantity,
            },
          )
          .toList(),
      'customer': {
        'name': name,
        'email': (email == null || email.trim().isEmpty) ? null : email,
        'phone': phone,
      },
      if (discountCode != null && discountCode.isNotEmpty)
        'discount_code': discountCode,
    });
    final data = json['data'] as Map<String, dynamic>?;
    if (data == null) throw AppException.badResponse();
    return Order.fromJson(data);
  }

  @override
  Future<List<Order>> listMine(String sessionToken) async {
    final json = await _client.get(
      '/widget/orders/mine',
      headers: {'X-Phone-Session': sessionToken},
    );
    final list = json['data'] as List<dynamic>? ?? const [];
    return list
        .map((o) => Order.fromJson(o as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<Order> getOrder(String orderId) async {
    final json = await _client.get('/widget/orders/$orderId');
    final data = json['data'] as Map<String, dynamic>?;
    if (data == null) throw AppException.badResponse();
    return Order.fromJson(data);
  }

  @override
  Future<String> createPayment(String orderId) async {
    final json = await _client.post('/widget/orders/$orderId/payment', {});
    final url = json['payment_url'] as String?;
    if (url == null) throw AppException.badResponse();
    return url;
  }

  @override
  Future<String> createSurchargePayment(String orderId) async {
    final json = await _client.post('/widget/orders/$orderId/surcharge-payment', {});
    final url = json['payment_url'] as String?;
    if (url == null) throw AppException.badResponse();
    return url;
  }
}
