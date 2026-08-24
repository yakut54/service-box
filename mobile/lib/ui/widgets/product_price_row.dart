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

    // +10% к размеру шрифта актуальной цены — так же на карточке в сетке,
    // на карточке товара и в «Похожих товарах», раз стиль ни один из вызовов
    // сам не увеличивает.
    final basePriceStyle =
        priceStyle ??
        theme.textTheme.titleSmall?.copyWith(
          color: theme.colorScheme.primary,
          fontWeight: FontWeight.bold,
        );

    // Крупные цены (5-6 цифр) в узкой карточке («Похожие товары», 130px)
    // не помещаются вместе со старой ценой — mainAxisSize.min тут не спасает
    // (он лишь про то, каким Row сообщает СВОЙ размер родителю, а не про то,
    // сколько места у него самого есть под детей). Актуальная цена — то,
    // что реально спишется, — не сокращается никогда; старая цена, если
    // не помещается, уходит в многоточие первой (Flexible), а не роняет
    // разметку с оранжево-чёрной полосой overflow.
    return Row(
      crossAxisAlignment: CrossAxisAlignment.baseline,
      textBaseline: TextBaseline.alphabetic,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          product.isWeightBased
              ? '${formatRubles(product.displayPriceKopecks / 100)}/кг'
              : formatRubles(product.displayPriceKopecks / 100),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: basePriceStyle?.copyWith(
            fontSize: (basePriceStyle.fontSize ?? 14) * 1.1,
          ),
        ),
        if (oldPriceKopecks != null) ...[
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              formatRubles(oldPriceKopecks / 100),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style:
                  oldPriceStyle ??
                  theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                    decoration: TextDecoration.lineThrough,
                  ),
            ),
          ),
        ],
      ],
    );
  }
}
