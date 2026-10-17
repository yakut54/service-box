import 'package:flutter/material.dart';

/// Русские подписи и цвета статуса заказа — повторяют widget/src/components/MyOrders.vue,
/// чтобы байер видел одинаковые формулировки в приложении и на сайте.
String orderStatusLabel(String status) {
  switch (status) {
    case 'pending':
      return 'Ожидает';
    case 'paid':
      return 'Оплачен';
    case 'processing':
      return 'В работе';
    case 'collected':
      return 'Собран';
    case 'in_delivery':
      return 'В доставке';
    case 'completed':
      return 'Завершён';
    case 'cancelled':
      return 'Отменён';
    case 'needs_attention':
      return 'Требует внимания';
    default:
      return status;
  }
}

Color orderStatusColor(BuildContext context, String status) {
  final scheme = Theme.of(context).colorScheme;
  switch (status) {
    case 'paid':
    case 'completed':
      return Colors.green;
    case 'processing':
      return scheme.primary;
    case 'collected':
      return Colors.teal;
    case 'in_delivery':
      return Colors.lightBlue;
    case 'cancelled':
    case 'needs_attention':
      return scheme.error;
    case 'pending':
    default:
      return Colors.orange;
  }
}

/// Один шаг визуального таймлайна статуса заказа (см. OrderStatusStepper).
class OrderStatusStep {
  final String key;
  final String label;
  const OrderStatusStep(this.key, this.label);
}

/// Шаги таймлайна — зеркало admin/src/shared/lib/labels.ts::orderStatusSteps
/// (та же логика: pending/paid схлопнуты в «Оформлен», in_delivery только
/// для курьера, финальная подпись отличается для курьера/самовывоза).
List<OrderStatusStep> orderStatusSteps(String? deliveryMethod) {
  final steps = <OrderStatusStep>[
    const OrderStatusStep('pending', 'Оформлен'),
    OrderStatusStep('processing', orderStatusLabel('processing')),
    OrderStatusStep('collected', orderStatusLabel('collected')),
  ];
  if (deliveryMethod == 'courier') {
    steps.add(OrderStatusStep('in_delivery', orderStatusLabel('in_delivery')));
    steps.add(const OrderStatusStep('completed', 'Доставлен'));
  } else {
    steps.add(OrderStatusStep('completed', orderStatusLabel('completed')));
  }
  return steps;
}

/// Индекс текущего шага среди orderStatusSteps() — см. docblock у
/// admin/src/shared/lib/labels.ts::orderStepIndex, та же логика.
int orderStepIndex(String status, String? deliveryMethod) {
  final steps = orderStatusSteps(deliveryMethod);
  final effectiveKey = status == 'paid'
      ? 'pending'
      : status == 'needs_attention'
          ? 'processing'
          : status;
  final idx = steps.indexWhere((s) => s.key == effectiveKey);
  return idx == -1 ? 0 : idx;
}
