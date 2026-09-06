import 'package:flutter/material.dart';

import '../../models/product.dart';

/// Селектор вариантов товара (одежда/обувь): ряд чипов-кнопок на каждую ось
/// (Baymard принцип 1 — кнопки, не выпадающий список). Недоступные значения
/// зачёркнуты и не выбираются. Родитель хранит выбор и резолвит вариант.
class VariantSelector extends StatelessWidget {
  final Product product;

  /// Выбранные значения по позициям опций (product.options). null — не выбрано.
  final List<String?> selected;

  /// (индекс опции, значение) — тап по чипу.
  final void Function(int optionIndex, String value) onSelect;

  const VariantSelector({
    super.key,
    required this.product,
    required this.selected,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (!product.hasVariants) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var oi = 0; oi < product.options.length; oi++) ...[
          Padding(
            padding: const EdgeInsets.only(bottom: 6, top: 4),
            child: Text(
              product.options[oi].name,
              style: theme.textTheme.titleSmall,
            ),
          ),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final value in product.options[oi].values)
                _Chip(
                  label: value,
                  selected: selected.length > oi && selected[oi] == value,
                  available: product.isOptionValueAvailable(oi, value, selected),
                  onTap: () => onSelect(oi, value),
                  theme: theme,
                ),
            ],
          ),
          const SizedBox(height: 8),
        ],
      ],
    );
  }
}

class _Chip extends StatelessWidget {
  final String label;
  final bool selected;
  final bool available;
  final VoidCallback onTap;
  final ThemeData theme;

  const _Chip({
    required this.label,
    required this.selected,
    required this.available,
    required this.onTap,
    required this.theme,
  });

  @override
  Widget build(BuildContext context) {
    final cs = theme.colorScheme;
    final bg = selected
        ? cs.primary
        : available
        ? cs.surface
        : cs.surfaceContainerHighest;
    final fg = selected
        ? cs.onPrimary
        : available
        ? cs.onSurface
        : cs.onSurfaceVariant;

    return InkWell(
      // Недоступное значение всё равно можно выбрать (покажет «нет в наличии» /
      // «сообщить о поступлении»), но визуально помечаем.
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        constraints: const BoxConstraints(minWidth: 48, minHeight: 44),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: bg,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
            color: selected ? cs.primary : cs.outlineVariant,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Text(
          label,
          style: theme.textTheme.labelLarge?.copyWith(
            color: fg,
            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            decoration: available ? null : TextDecoration.lineThrough,
          ),
        ),
      ),
    );
  }
}
