import 'package:flutter_test/flutter_test.dart';
import 'package:mobile/core/checkout_payment_flow.dart';
import 'package:mobile/data/order_repository.dart';
import 'package:mobile/models/cart_item.dart';
import 'package:mobile/models/order.dart';

/// Фейковый репозиторий — только createPayment настраивается под сценарий,
/// остальные методы этим тестам не нужны (падают с UnimplementedError, если
/// их вдруг случайно вызовут — сразу видно, что тест задел не то).
class _FakeOrderRepository implements OrderRepository {
  _FakeOrderRepository({this.paymentUrl, this.throwOnCreatePayment = false});

  final String? paymentUrl;
  final bool throwOnCreatePayment;
  bool createPaymentCalled = false;

  @override
  Future<String> createPayment(String orderId) async {
    createPaymentCalled = true;
    if (throwOnCreatePayment) {
      throw Exception('сеть недоступна');
    }
    return paymentUrl!;
  }

  @override
  Future<Order> createOrder({
    required String name,
    String? email,
    required String phone,
    required List<CartItem> items,
    String? discountCode,
  }) =>
      throw UnimplementedError();

  @override
  Future<List<Order>> listMine(String sessionToken) => throw UnimplementedError();

  @override
  Future<Order> getOrder(String orderId) => throw UnimplementedError();

  @override
  Future<String> createSurchargePayment(String orderId) => throw UnimplementedError();

  @override
  Future<Order> confirmReceived(String orderId, String sessionToken) =>
      throw UnimplementedError();
}

void main() {
  group('CheckoutPaymentFlow', () {
    test('не создаёт платёж, если у магазина не включена онлайн-оплата', () async {
      final repo = _FakeOrderRepository(paymentUrl: 'https://yoomoney.ru/x');
      final flow = CheckoutPaymentFlow(repo);

      final url = await flow.maybeCreatePaymentUrl(
        orderId: 'order-1',
        onlinePaymentEnabled: false,
      );

      expect(url, isNull);
      expect(repo.createPaymentCalled, isFalse);
    });

    test('создаёт платёж и возвращает ссылку, если оплата включена', () async {
      final repo = _FakeOrderRepository(paymentUrl: 'https://yoomoney.ru/checkout/x');
      final flow = CheckoutPaymentFlow(repo);

      final url = await flow.maybeCreatePaymentUrl(
        orderId: 'order-1',
        onlinePaymentEnabled: true,
      );

      expect(url, 'https://yoomoney.ru/checkout/x');
      expect(repo.createPaymentCalled, isTrue);
    });

    test('не бросает исключение наружу, если создание платежа упало — заказ не должен блокироваться', () async {
      final repo = _FakeOrderRepository(throwOnCreatePayment: true);
      final flow = CheckoutPaymentFlow(repo);

      final url = await flow.maybeCreatePaymentUrl(
        orderId: 'order-1',
        onlinePaymentEnabled: true,
      );

      expect(url, isNull);
      expect(repo.createPaymentCalled, isTrue);
    });
  });
}
