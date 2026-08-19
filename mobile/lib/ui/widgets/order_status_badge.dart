import 'package:flutter/material.dart';

import '../../core/order_status.dart';

/// Цветной бейдж статуса заказа — используется на экране подтверждения
/// заказа и в истории заказов профиля, чтобы не дублировать вёрстку.
class OrderStatusBadge extends StatelessWidget {
  final String status;

  const OrderStatusBadge({super.key, required this.status});

  @override
  Widget build(BuildContext context) {
    final color = orderStatusColor(context, status);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        orderStatusLabel(status),
        style: Theme.of(context).textTheme.labelMedium?.copyWith(
          color: color,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}
