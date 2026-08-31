import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../data/order_repository.dart';
import '../models/order.dart';

/// Подтверждение доплаты за перевзвешенный заказ (см. PLAN.md, «По весу —
/// перевзвешивание»). Живой обратный отсчёт до surcharge_deadline_at —
/// после дедлайна крон (CheckSurchargeDeadline) переводит заказ в
/// needs_attention, доплата пропадает.
class OrderSurchargeScreen extends StatefulWidget {
  final Order order;

  const OrderSurchargeScreen({super.key, required this.order});

  @override
  State<OrderSurchargeScreen> createState() => _OrderSurchargeScreenState();
}

class _OrderSurchargeScreenState extends State<OrderSurchargeScreen> {
  Timer? _timer;
  Duration _remaining = Duration.zero;
  bool _launching = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _tick();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) => _tick());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  void _tick() {
    final deadline = widget.order.surchargeDeadlineAt;
    if (deadline == null) return;
    final remaining = deadline.difference(DateTime.now());
    if (!mounted) return;
    setState(() => _remaining = remaining.isNegative ? Duration.zero : remaining);
    if (remaining.isNegative) _timer?.cancel();
  }

  Future<void> _pay() async {
    setState(() {
      _launching = true;
      _error = null;
    });
    try {
      final url = widget.order.surchargePaymentUrl ??
          await OrderRepository.create().createSurchargePayment(widget.order.id);
      final uri = Uri.parse(url);
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
        if (mounted) Navigator.of(context).pop(true);
        return;
      }
      setState(() => _error = 'Не удалось открыть страницу оплаты');
    } on AppException catch (e) {
      setState(() => _error = e.message);
    } catch (_) {
      setState(() => _error = 'Не удалось создать платёж. Попробуйте позже.');
    } finally {
      if (mounted) setState(() => _launching = false);
    }
  }

  String _formatRemaining(Duration d) {
    final h = d.inHours;
    final m = d.inMinutes.remainder(60);
    final s = d.inSeconds.remainder(60);
    return '${h.toString().padLeft(2, '0')}:${m.toString().padLeft(2, '0')}:${s.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final amount = (widget.order.surchargeAmountKopecks ?? 0) / 100;
    final expired = _remaining == Duration.zero &&
        widget.order.surchargeDeadlineAt != null &&
        widget.order.surchargeDeadlineAt!.isBefore(DateTime.now());

    return Scaffold(
      appBar: AppBar(title: const Text('Доплата за заказ')),
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.scale_outlined, size: 56, color: theme.colorScheme.primary),
                const SizedBox(height: 16),
                Text(
                  'Фактический вес товара оказался больше заявленного при оформлении',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodyMedium,
                ),
                const SizedBox(height: 20),
                Text(formatRubles(amount), style: theme.textTheme.headlineMedium),
                const SizedBox(height: 8),
                if (!expired)
                  Text(
                    'Осталось: ${_formatRemaining(_remaining)}',
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  )
                else
                  Text(
                    'Время на подтверждение истекло',
                    style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.error),
                  ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(_error!, style: TextStyle(color: theme.colorScheme.error), textAlign: TextAlign.center),
                ],
                const SizedBox(height: 24),
                FilledButton(
                  onPressed: (_launching || expired) ? null : _pay,
                  child: _launching
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('Оплатить'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
