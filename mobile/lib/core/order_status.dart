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
