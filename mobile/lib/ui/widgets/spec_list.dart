import 'package:flutter/material.dart';

/// Блок «сухих фактов» карточки товара — список пар «ключ: значение».
/// Раньше жил приватным классом в product_detail_screen.dart только под
/// структурные поля (вес/размер/цвет); вынесен сюда, чтобы тем же виджетом
/// показывать и произвольные характеристики из админки (ProductAttribute).
///
/// Baymard принцип 6: спецификации идут отдельным списком, не растворяются
/// в описании.
class SpecList extends StatelessWidget {
  final List<(String, String)> rows;
  final String title;

  const SpecList({super.key, required this.rows, this.title = 'Характеристики'});

  @override
  Widget build(BuildContext context) {
    if (rows.isEmpty) return const SizedBox.shrink();

    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        for (final (label, value) in rows)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(
                  width: 120,
                  child: Text(
                    label,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
                Expanded(child: Text(value, style: theme.textTheme.bodyMedium)),
              ],
            ),
          ),
      ],
    );
  }
}
