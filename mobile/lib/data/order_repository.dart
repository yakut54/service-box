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

  factory OrderRepository.create() => ApiOrderRepository();
}
