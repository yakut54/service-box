import 'package:flutter/material.dart';

import '../../core/format.dart';
import '../../models/product.dart';

/// Цена товара + (если есть) зачёркнутая старая цена рядом — единая логика
/// для карточки в сетке (ProductCard), карточки товара (ProductDetailScreen)
/// и ленты «Похожие товары» (_RelatedProductCard), чтобы приоритет
/// «реальная скидка / compare_price» не разъезжался по трём копиям
/// (см. Product.displayPriceKopecks / oldPriceKopecks).
class ProductPriceRow extends StatelessWidget {
  final Product product;
  final TextStyle? priceStyle;
  final TextStyle? oldPriceStyle;

  const ProductPriceRow({
    super.key,
    required this.product,
    this.priceStyle,
    this.oldPriceStyle,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final oldPriceKopecks = product.oldPriceKopecks;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.baseline,
      textBaseline: TextBaseline.alphabetic,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          formatRubles(product.displayPriceKopecks / 100),
          style:
              priceStyle ??
              theme.textTheme.titleSmall?.copyWith(
                color: theme.colorScheme.primary,
                fontWeight: FontWeight.bold,
              ),
        ),
        if (oldPriceKopecks != null) ...[
          const SizedBox(width: 6),
          Text(
            formatRubles(oldPriceKopecks / 100),
            style:
                oldPriceStyle ??
                theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                  decoration: TextDecoration.lineThrough,
                ),
          ),
        ],
      ],
    );
  }
}
