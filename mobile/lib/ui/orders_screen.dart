import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../data/order_repository.dart';
import '../models/order.dart';
import '../state/auth_state.dart';
import 'order_detail_screen.dart';
import 'widgets/error_view.dart';
import 'widgets/order_status_badge.dart';
import 'widgets/skeleton.dart';

/// История заказов авторизованного байера.
class OrdersScreen extends StatefulWidget {
  const OrdersScreen({super.key});

  @override
  State<OrdersScreen> createState() => OrdersScreenState();
}

/// Публичный (не `_`-приватный) State — HomeShell держит на него GlobalKey и
/// дёргает reload() при переключении на вкладку «Заказы» (см. HomeShell,
/// docblock у reload()). Экран живёт внутри IndexedStack и никогда не
/// пересоздаётся при переключении вкладок — без этого список заказов не
/// обновлялся бы сам после оформления нового заказа, пока не потянуть
/// список руками (баг найден живым тестом 2026-09-16).
class OrdersScreenState extends State<OrdersScreen> {
  List<Order> _orders = [];
  bool _loading = true;
  AppException? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// Вызывается HomeShell при каждом переключении на вкладку «Заказы» —
  /// не только сразу после оформления нового заказа, но и на случай, если
  /// статус уже существующего заказа сменился, пока байер был на другой
  /// вкладке (например, магазин перевёл заказ «В работу»).
  Future<void> reload() => _load();

  Future<void> _load() async {
    final token = context.read<AuthState>().session?.sessionToken;
    if (token == null) return;

    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      _orders = await OrderRepository.create().listMine(token);
    } on AppException catch (e) {
      setState(() => _error = e);
    } catch (_) {
      setState(() => _error = AppException.unknown());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Мои заказы')),
      body: SafeArea(child: _buildBody(context)),
    );
  }

  Widget _buildBody(BuildContext context) {
    if (_loading && _orders.isEmpty) {
      return const _OrdersListSkeleton();
    }

    if (_error != null && _orders.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: ErrorView(error: _error!, onRetry: _load),
        ),
      );
    }

    if (_orders.isEmpty) {
      final theme = Theme.of(context);
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.receipt_long_outlined,
                size: 48,
                color: theme.colorScheme.onSurfaceVariant.withValues(
                  alpha: 0.5,
                ),
              ),
              const SizedBox(height: 12),
              Text('Заказов пока нет', style: theme.textTheme.titleMedium),
            ],
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.all(12),
        itemCount: _orders.length,
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (context, index) => _OrderTile(order: _orders[index]),
      ),
    );
  }
}

/// Skeleton первой загрузки — повторяет форму _OrderTile ниже.
class _OrdersListSkeleton extends StatelessWidget {
  const _OrdersListSkeleton();

  @override
  Widget build(BuildContext context) {
    return Shimmer(
      child: ListView.separated(
        padding: const EdgeInsets.all(12),
        physics: const NeverScrollableScrollPhysics(),
        itemCount: 6,
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (context, index) => Card(
          clipBehavior: Clip.antiAlias,
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const SkeletonBox(width: 140, height: 15),
                      const SizedBox(height: 6),
                      SkeletonBox(width: 100, height: 13),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                const SkeletonBox(width: 70, height: 24, borderRadius: BorderRadius.all(Radius.circular(12))),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _OrderTile extends StatelessWidget {
  final Order order;

  const _OrderTile({required this.order});

  String get _shortId => order.id.length > 8 ? order.id.substring(0, 8) : order.id;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final createdAt = order.createdAt;

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => OrderDetailScreen(orderId: order.id)),
        ),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Заказ №$_shortId',
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          [
                            if (createdAt != null) formatShortDate(createdAt),
                            formatRubles(order.totalRubles),
                          ].join(' · '),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 12),
                  OrderStatusBadge(status: order.status),
                ],
              ),
              if (order.hasPendingSurcharge) ...[
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: theme.colorScheme.errorContainer,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.warning_amber_rounded, size: 16, color: theme.colorScheme.onErrorContainer),
                      const SizedBox(width: 6),
                      Text(
                        'Требуется доплата ${formatRubles((order.surchargeAmountKopecks ?? 0) / 100)}',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onErrorContainer,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
