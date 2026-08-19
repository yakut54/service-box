import '../models/cart_item.dart';
import '../models/order.dart';
import 'api_order_repository.dart';

/// Оформление заказа. MVP — только самовывоз, без доставки и без OTP
/// (подтверждение телефона нужно только для истории заказов, не для
/// самого оформления — см. routes/api.php: POST /widget/orders не
/// защищён verify.phone).
abstract class OrderRepository {
  Future<Order> createOrder({
    required String name,
    required String email,
    required String phone,
    required List<CartItem> items,
    String? discountCode,
  });

  /// История заказов авторизованного байера (см. GET /widget/orders/mine,
  /// защищён 60-дневной сессией — не путать с 30-минутным OTP-токеном,
  /// который используется вебом).
  Future<List<Order>> listMine(String sessionToken);

  factory OrderRepository.create() => ApiOrderRepository();
}
