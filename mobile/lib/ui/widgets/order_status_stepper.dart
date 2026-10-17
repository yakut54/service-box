import 'package:flutter/material.dart';

import '../../core/order_status.dart';

/// Визуальный таймлайн статуса заказа — зеркало
/// admin/src/shared/ui/UiOrderStepper.vue (тот же дизайн: единый акцентный
/// цвет для пройденных/текущего шага, серый контур для будущих, отдельный
/// баннер для cancelled, розовая точка-предупреждение для needs_attention).
/// OrderStatusBadge в хедере экрана остаётся — это отдельная карточка ниже.
class OrderStatusStepper extends StatelessWidget {
  final String status;
  final String? deliveryMethod;

  const OrderStatusStepper({
    super.key,
    required this.status,
    this.deliveryMethod,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (status == 'cancelled') {
      return Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Icon(Icons.close, size: 20, color: theme.colorScheme.error),
              const SizedBox(width: 8),
              Text(
                'Заказ отменён',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.error,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      );
    }

    final steps = orderStatusSteps(deliveryMethod);
    final currentIndex = orderStepIndex(status, deliveryMethod);
    final isStuck = status == 'needs_attention';
    final accent = theme.colorScheme.primary;
    final stuckColor = Colors.pink.shade600;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (var i = 0; i < steps.length; i++) ...[
                  if (i > 0)
                    Expanded(
                      child: Container(
                        height: 2,
                        margin: const EdgeInsets.only(top: 11),
                        color: i <= currentIndex
                            ? accent
                            : theme.colorScheme.outlineVariant,
                      ),
                    ),
                  Column(
                    children: [
                      _StepDot(
                        done: i < currentIndex,
                        current: i == currentIndex,
                        stuck: i == currentIndex && isStuck,
                        accent: accent,
                        stuckColor: stuckColor,
                        outline: theme.colorScheme.outlineVariant,
                      ),
                      const SizedBox(height: 6),
                      SizedBox(
                        width: 56,
                        child: Text(
                          steps[i].label,
                          textAlign: TextAlign.center,
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: i == currentIndex
                                ? theme.colorScheme.onSurface
                                : theme.colorScheme.onSurfaceVariant,
                            fontWeight: i == currentIndex ? FontWeight.w700 : FontWeight.w400,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            ),
            if (isStuck) ...[
              const SizedBox(height: 10),
              Text(
                '⚠ Требует внимания — сборка остановлена',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: stuckColor,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _StepDot extends StatelessWidget {
  final bool done;
  final bool current;
  final bool stuck;
  final Color accent;
  final Color stuckColor;
  final Color outline;

  const _StepDot({
    required this.done,
    required this.current,
    required this.stuck,
    required this.accent,
    required this.stuckColor,
    required this.outline,
  });

  @override
  Widget build(BuildContext context) {
    final color = stuck ? stuckColor : accent;
    final filled = done || current;

    return Container(
      width: 22,
      height: 22,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: filled ? color : Colors.transparent,
        border: Border.all(color: filled ? color : outline, width: 2),
      ),
      child: done
          ? const Icon(Icons.check, size: 14, color: Colors.white)
          : stuck
              ? const Icon(Icons.priority_high, size: 13, color: Colors.white)
              : current
                  ? Center(
                      child: Container(
                        width: 7,
                        height: 7,
                        decoration: const BoxDecoration(shape: BoxShape.circle, color: Colors.white),
                      ),
                    )
                  : null,
    );
  }
}
