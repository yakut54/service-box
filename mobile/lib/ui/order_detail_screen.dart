import 'package:flutter/material.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../data/order_repository.dart';
import '../models/order.dart';
import 'order_surcharge_screen.dart';
import 'widgets/error_view.dart';
import 'widgets/order_status_badge.dart';

/// Детали заказа — состав, статус, факт. вес по позициям «по весу —
/// перевзвешивание» (см. PLAN.md). Открывается тапом по карточке из
/// OrdersScreen; перезагружает заказ при открытии, т.к. сборщик мог
/// довзвесить товар уже после того, как список был загружен.
class OrderDetailScreen extends StatefulWidget {
  final String orderId;

  const OrderDetailScreen({super.key, required this.orderId});

  @override
  State<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends State<OrderDetailScreen> {
  Order? _order;
  bool _loading = true;
  AppException? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final order = await OrderRepository.create().getOrder(widget.orderId);
      if (mounted) setState(() => _order = order);
    } on AppException catch (e) {
      if (mounted) setState(() => _error = e);
    } catch (_) {
      if (mounted) setState(() => _error = AppException.unknown());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String get _shortId =>
      widget.orderId.length > 8 ? widget.orderId.substring(0, 8) : widget.orderId;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Заказ №$_shortId')),
      body: SafeArea(child: _buildBody(context)),
    );
  }

  Widget _buildBody(BuildContext context) {
    final order = _order;

    if (_loading && order == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && order == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: ErrorView(error: _error!, onRetry: _load),
        ),
      );
    }
    if (order == null) return const SizedBox.shrink();

    final theme = Theme.of(context);

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              if (order.createdAt != null)
                Text(
                  formatShortDate(order.createdAt!),
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              OrderStatusBadge(status: order.status),
            ],
          ),
          const SizedBox(height: 16),
          if (order.hasPendingSurcharge) _SurchargeBanner(order: order, onPaid: _load),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Состав заказа', style: theme.textTheme.titleSmall),
                  const SizedBox(height: 8),
                  for (final item in order.items) _OrderItemRow(item: item),
                  const Divider(height: 24),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text('Итого', style: theme.textTheme.titleSmall),
                      Text(
                        formatRubles(order.totalRubles),
                        style: theme.textTheme.titleSmall,
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _OrderItemRow extends StatelessWidget {
  final OrderItem item;

  const _OrderItemRow({required this.item});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final weightGrams = item.weightGrams;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(item.productName, style: theme.textTheme.bodyMedium),
                if ((item.variantLabel ?? '').isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    item.variantLabel!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
                if (weightGrams != null) ...[
                  const SizedBox(height: 2),
                  if (item.isAwaitingWeighing)
                    Text(
                      'Заявлено ≈ ${formatWeight(weightGrams)} · ждёт взвешивания',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    )
                  else if (item.actualWeightGrams != null)
                    Text(
                      'Заявлено: ${formatWeight(weightGrams)} → Факт: ${formatWeight(item.actualWeightGrams!)}',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                ] else if (item.quantity > 1)
                  Text(
                    '× ${item.quantity}',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Text(formatRubles(item.priceRubles), style: theme.textTheme.bodyMedium),
        ],
      ),
    );
  }
}

class _SurchargeBanner extends StatelessWidget {
  final Order order;
  final VoidCallback onPaid;

  const _SurchargeBanner({required this.order, required this.onPaid});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final amount = (order.surchargeAmountKopecks ?? 0) / 100;

    return Card(
      color: theme.colorScheme.errorContainer,
      margin: const EdgeInsets.only(bottom: 16),
      child: ListTile(
        leading: Icon(Icons.warning_amber_rounded, color: theme.colorScheme.onErrorContainer),
        title: Text(
          'Требуется доплата ${formatRubles(amount)}',
          style: TextStyle(color: theme.colorScheme.onErrorContainer, fontWeight: FontWeight.w600),
        ),
        subtitle: Text(
          'Фактический вес оказался больше заявленного',
          style: TextStyle(color: theme.colorScheme.onErrorContainer),
        ),
        trailing: const Icon(Icons.chevron_right),
        onTap: () async {
          final paid = await Navigator.of(context).push<bool>(
            MaterialPageRoute(builder: (_) => OrderSurchargeScreen(order: order)),
          );
          if (paid == true) onPaid();
        },
      ),
    );
  }
}
