import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/format.dart';
import '../models/order.dart';
import '../services/notification_permission.dart';
import 'widgets/order_status_badge.dart';

/// Показывается сразу после успешного оформления заказа (в т.ч. для заказов
/// с холдом ЮKassa — см. CheckoutScreen._submit, окно оплаты открывается до
/// перехода на этот экран). Заказ создаётся со статусом «ожидает
/// подтверждения» — это самостоятельный валидный исход оформления.
class OrderConfirmationScreen extends StatefulWidget {
  final Order order;

  /// Ссылка на оплату, которую CheckoutScreen уже попытался открыть сам —
  /// если это не удалось (например, браузер не открылся), кнопка ниже даёт
  /// открыть её ещё раз вручную, не оставляя покупателя в тупике (найдено
  /// живым тестом 2026-10-20 — автооткрытие на Android молча не срабатывало).
  final String? paymentUrl;

  const OrderConfirmationScreen({super.key, required this.order, this.paymentUrl});

  @override
  State<OrderConfirmationScreen> createState() => _OrderConfirmationScreenState();
}

class _OrderConfirmationScreenState extends State<OrderConfirmationScreen> {
  bool _opening = false;

  Future<void> _openPayment() async {
    final url = widget.paymentUrl;
    if (url == null) return;

    setState(() => _opening = true);
    try {
      final uri = Uri.parse(url);
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
      } else if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Не удалось открыть страницу оплаты')),
        );
      }
    } finally {
      if (mounted) setState(() => _opening = false);
    }
  }

  @override
  void initState() {
    super.initState();
    // Момент очевидной ценности: заказ оформлен — спрашиваем про уведомления
    // о статусе доставки. Не на старте приложения.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        NotificationPermission.maybeShowPrimer(context, trigger: 'order');
      }
    });
  }

  String get _shortId {
    final id = widget.order.id;
    return id.length > 8 ? id.substring(0, 8) : id;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final order = widget.order;

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
                if (widget.paymentUrl != null) ...[
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: _opening ? null : _openPayment,
                    child: Text(_opening ? 'Открываем оплату...' : 'Оплатить'),
                  ),
                ],
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
