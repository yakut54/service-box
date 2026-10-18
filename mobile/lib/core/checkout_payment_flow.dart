import '../data/order_repository.dart';

/// Решает, нужно ли инициировать онлайн-оплату сразу после оформления
/// заказа, и создаёт платёж, если да. Вынесено из CheckoutScreen отдельным
/// классом без зависимости от BuildContext/Navigator/url_launcher — чтобы
/// само решение "оплата включена у магазина → создать платёж" можно было
/// проверить юнит-тестом, не поднимая весь виджет.
class CheckoutPaymentFlow {
  final OrderRepository repository;

  const CheckoutPaymentFlow(this.repository);

  /// Раньше оплата запускалась только для товаров «по весу —
  /// перевзвешивание» (там сразу холд) — обычные заказы не получали
  /// онлайн-оплату вообще, навсегда оставаясь «Ожидает оплаты» (найдено
  /// живым разбором 2026-10-17). Теперь это решает не состав корзины, а сам
  /// магазин: если у него подключена ЮKassa — оплата запускается для ЛЮБОГО
  /// заказа; холд это или обычное списание решает бэкенд сам
  /// (PaymentController::createOrderPayment).
  ///
  /// Возвращает ссылку на оплату, которую вызывающий код должен открыть, или
  /// null — если магазин без ЮKassa, или платёж не удалось создать сразу
  /// (не блокируем подтверждение заказа из-за этого — владелец увидит заказ
  /// в админке и разберётся вручную).
  Future<String?> maybeCreatePaymentUrl({
    required String orderId,
    required bool onlinePaymentEnabled,
  }) async {
    if (!onlinePaymentEnabled) return null;

    try {
      return await repository.createPayment(orderId);
    } catch (_) {
      return null;
    }
  }
}
