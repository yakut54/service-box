import 'package:flutter/material.dart';

import '../core/format.dart';
import '../models/order.dart';
import 'widgets/order_status_badge.dart';

/// Показывается сразу после успешного оформления заказа. Оплата (YooKassa)
/// подключается отдельным шагом — сейчас заказ создаётся со статусом
/// «ожидает подтверждения», это самостоятельный валидный исход оформления.
class OrderConfirmationScreen extends StatelessWidget {
  final Order order;

  const OrderConfirmationScreen({super.key, required this.order});

  String get _shortId =>
      order.id.length > 8 ? order.id.substring(0, 8) : order.id;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(automaticallyImplyLeading: false),
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  Icons.check_circle_outline_rounded,
                  size: 64,
                  color: theme.colorScheme.primary,
                ),
                const SizedBox(height: 16),
                Text(
                  'Заказ оформлен',
                  style: theme.textTheme.headlineSmall,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 8),
                Text(
                  'Номер заказа: $_shortId',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  'Сумма: ${formatRubles(order.totalRubles)}',
                  style: theme.textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                OrderStatusBadge(status: order.status),
                const SizedBox(height: 24),
                FilledButton(
                  onPressed: () =>
                      Navigator.of(context).popUntil((route) => route.isFirst),
                  child: const Text('Вернуться к покупкам'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
