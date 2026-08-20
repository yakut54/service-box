import 'package:flutter/material.dart';

/// Бейдж «-N%» на карточке товара — единственное место, где решается его
/// вид, чтобы карточки в сетке каталога (ProductCard) и в горизонтальных
/// каруселях («Похожие товары» и т.п.) выглядели одинаково.
///
/// Скруглённый прямоугольник, а не круг — плашка-тег читается как часть
/// интерфейса, а не как отдельная фигура поверх фото (жёсткий контур круга
/// с белым текстом слишком контрастен на маленьком размере).
class DiscountBadge extends StatelessWidget {
  final int percent;

  const DiscountBadge({super.key, required this.percent});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: theme.colorScheme.error,
        borderRadius: BorderRadius.circular(6),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.15),
            blurRadius: 3,
            offset: const Offset(0, 1),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 4),
        child: Text(
          '−$percent%',
          style: theme.textTheme.labelSmall?.copyWith(
            color: theme.colorScheme.onError,
            fontWeight: FontWeight.w700,
            fontSize: 11,
            height: 1,
            letterSpacing: 0.2,
          ),
        ),
      ),
    );
  }
}
