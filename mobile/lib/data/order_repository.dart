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
    String? email,
    required String phone,
    required List<CartItem> items,
    String? discountCode,
  });

  /// История заказов авторизованного байера (см. GET /widget/orders/mine,
  /// защищён 60-дневной сессией — не путать с 30-минутным OTP-токеном,
  /// который используется вебом).
  Future<List<Order>> listMine(String sessionToken);

  /// Один заказ по id — для экрана деталей (см. GET /widget/orders/{id}).
  /// UUID заказа сам по себе выступает токеном доступа (как и на вебе,
  /// см. OrderSuccess.vue) — отдельной авторизации не требует.
  Future<Order> getOrder(String orderId);

  /// Холд ЮKassa за заказ с товаром weight_variable (см.
  /// PaymentController::createOrderPayment) — вызывается сразу после
  /// createOrder, до этого сумма не известна точно, списывать нечего.
  Future<String> createPayment(String orderId);

  /// Доплата за перевзвешенный заказ (см. PaymentController::createOrderSurchargePayment) —
  /// возвращает ссылку на оплату ЮKassa.
  Future<String> createSurchargePayment(String orderId);

  factory OrderRepository.create() => ApiOrderRepository();
}
