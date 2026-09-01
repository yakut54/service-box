import 'package:flutter/material.dart';

import 'primary_submit_button.dart';

/// «Нет в наличии» — общий чип для штучных и весовых товаров (раньше жил
/// приватным классом внутри add_to_cart_control.dart и не мог переиспользоваться
/// из weight_cart_control.dart из-за приватности на уровне файла в Dart).
class OutOfStockChip extends StatelessWidget {
  final bool compact;
  final ThemeData theme;

  const OutOfStockChip({super.key, required this.compact, required this.theme});

  @override
  Widget build(BuildContext context) {
    if (compact) {
      return SizedBox(
        width: double.infinity,
        height: 36,
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(10),
          ),
          child: Center(
            child: Text(
              'Нет в наличии',
              style: theme.textTheme.labelMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
        ),
      );
    }
    return const PrimarySubmitButton(label: 'Нет в наличии', onPressed: null);
  }
}
