import 'package:flutter/material.dart';

/// Красный кружок с числом — общий переиспользуемый бейдж: поверх иконки
/// (чат/корзина в шапке, через Stack+Positioned у вызывающего кода) или
/// инлайн в списке (см. «Чат с магазином» в профиле). Раньше один и тот же
/// Container+BoxDecoration+Text был скопирован по отдельности в
/// chat_button.dart, cart_button.dart и account_screen.dart.
class NotificationBadge extends StatelessWidget {
  final int count;
  final int max;

  const NotificationBadge({super.key, required this.count, this.max = 99});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
      decoration: BoxDecoration(
        color: theme.colorScheme.error,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(
        count > max ? '$max+' : '$count',
        style: theme.textTheme.labelSmall?.copyWith(
          color: theme.colorScheme.onError,
        ),
      ),
    );
  }
}
