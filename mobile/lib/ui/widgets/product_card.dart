import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart';

import '../../core/format.dart';
import '../../models/product.dart';
import '../../services/age_gate.dart';
import '../product_detail_screen.dart';
import 'add_to_cart_control.dart';
import 'discount_badge.dart';
import 'product_price_row.dart';
import 'star_rating.dart';

/// Карточка товара в сетке каталога: фото, название, цена, бейдж наличия.
/// Тап открывает карточку товара (ProductDetailScreen).
class ProductCard extends StatelessWidget {
  final Product product;

  const ProductCard({super.key, required this.product});

  /// Открыть карточку товара, спросив возраст, если категория 18+ и байер
  /// ещё не подтверждал.
  Future<void> _open(BuildContext context) async {
    if (product.categoryAgeRestricted && !AgeGate.confirmed.value) {
      final ok = await AgeGate.ensure(context);
      if (!ok) return;
    }
    if (!context.mounted) return;
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ProductDetailScreen(productId: product.id),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final imageUrl = product.imageUrl;

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => _open(context),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            AspectRatio(
              aspectRatio: 1,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  Container(
                    color: theme.colorScheme.surfaceContainerHighest,
                    child: (imageUrl != null && imageUrl.isNotEmpty)
                        ? Image.network(
                            imageUrl,
                            fit: BoxFit.cover,
                            errorBuilder: (_, _, _) => _placeholderIcon(theme),
                          )
                        : _placeholderIcon(theme),
                  ),
                  // 18+ категория: блюр фото + плашка, пока байер не подтвердил
                  // возраст (research §5.4 — гейт до показа фото).
                  if (product.categoryAgeRestricted)
                    ValueListenableBuilder<bool>(
                      valueListenable: AgeGate.confirmed,
                      builder: (context, confirmed, _) {
                        if (confirmed) return const SizedBox.shrink();
                        return ClipRect(
                          child: BackdropFilter(
                            filter: ImageFilter.blur(sigmaX: 14, sigmaY: 14),
                            child: Container(
                              alignment: Alignment.center,
                              color: theme.colorScheme.surface.withValues(alpha: 0.35),
                              child: Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 10,
                                  vertical: 4,
                                ),
                                decoration: BoxDecoration(
                                  color: theme.colorScheme.surface.withValues(alpha: 0.9),
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: Text(
                                  '18+',
                                  style: theme.textTheme.labelLarge?.copyWith(
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                              ),
                            ),
                          ),
                        );
                      },
                    ),
                  if (!product.inStock)
                    Positioned(
                      top: 8,
                      left: 8,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 8,
                          vertical: 4,
                        ),
                        decoration: BoxDecoration(
                          color: theme.colorScheme.errorContainer,
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          'Нет в наличии',
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: theme.colorScheme.onErrorContainer,
                          ),
                        ),
                      ),
                    ),
                  if (product.hasDiscount)
                    Positioned(
                      top: 8,
                      right: 8,
                      child: DiscountBadge(percent: product.discountPercent!),
                    ),
                ],
              ),
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(10, 8, 10, 0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      product.name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                        height: 1.2,
                      ),
                    ),
                    // Рейтинг рядом с ценой, не в подвале карточки (Baymard №7).
                    if (product.reviewCount > 0) ...[
                      const SizedBox(height: 3),
                      Row(
                        children: [
                          StarRating(value: product.rating ?? 0, size: 12),
                          const SizedBox(width: 4),
                          Flexible(
                            child: Text(
                              '${(product.rating ?? 0).toStringAsFixed(1)} · ${product.reviewCount}',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.labelSmall?.copyWith(
                                color: theme.colorScheme.onSurfaceVariant,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ],
                    const Spacer(),
                    if (product.hasVariants &&
                        product.variantPriceRangeKopecks != null)
                      Text(
                        _rangeLabel(product.variantPriceRangeKopecks!),
                        style: theme.textTheme.titleSmall?.copyWith(
                          color: theme.colorScheme.primary,
                          fontWeight: FontWeight.bold,
                          fontSize:
                              (theme.textTheme.titleSmall?.fontSize ?? 14) * 1.1,
                        ),
                      )
                    else
                      ProductPriceRow(product: product),
                    // Цена за единицу — Baymard №3 (сравнимость выгодности).
                    if (product.unitPriceKopecks != null)
                      Text(
                        '${formatRubles(product.unitPriceKopecks! / 100)}/${product.unitLabel}',
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                  ],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(6, 8, 6, 10),
              child: product.hasVariants
                  ? _ChooseButton(product: product, theme: theme)
                  : AddToCartControl(product: product, compact: true),
            ),
          ],
        ),
      ),
    );
  }

  Widget _placeholderIcon(ThemeData theme) => Icon(
    Icons.image_outlined,
    size: 32,
    color: theme.colorScheme.onSurfaceVariant.withValues(alpha: 0.4),
  );

  static String _rangeLabel((int, int) range) {
    final (lo, hi) = range;
    return lo == hi ? formatRubles(lo / 100) : 'от ${formatRubles(lo / 100)}';
  }
}

/// Кнопка «Выбрать» на карточке товара с вариантами — быстрый add невозможен,
/// нужно открыть карточку и выбрать размер/цвет.
class _ChooseButton extends StatelessWidget {
  final Product product;
  final ThemeData theme;

  const _ChooseButton({required this.product, required this.theme});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      height: 36,
      child: Material(
        color: theme.colorScheme.primary,
        borderRadius: BorderRadius.circular(10),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => ProductDetailScreen(productId: product.id),
            ),
          ),
          child: Center(
            child: Text(
              'Выбрать',
              style: theme.textTheme.labelMedium?.copyWith(
                color: theme.colorScheme.onPrimary,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
