import 'package:flutter/material.dart';

import '../../core/format.dart';
import '../../models/product.dart';
import '../product_reviews_screen.dart';
import 'chat_button.dart';

/// Компактная строка «рейтинг + Спросить» под названием товара — по образцу
/// Ozon/Wildberries: две ячейки в общем контейнере, левая ведёт на отдельный
/// экран со всеми отзывами (ProductReviewsScreen), правая — в чат с
/// магазином с предзаполненным вопросом. Заменяет старую RatingSummary
/// (только показывала цифру, никуда не вела) и инлайн-разворачивание
/// отзывов на этой же странице (ProductReviewsSection, теперь отдельный
/// экран) — единственный потребитель обеих, поглощает их.
class ProductRatingAskRow extends StatelessWidget {
  final Product product;

  const ProductRatingAskRow({super.key, required this.product});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(12),
      ),
      child: IntrinsicHeight(
        child: Row(
          children: [
            Expanded(
              child: _Cell(
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => ProductReviewsScreen(product: product),
                  ),
                ),
                child: _RatingContent(rating: product.rating, reviewCount: product.reviewCount),
              ),
            ),
            VerticalDivider(width: 1, color: theme.colorScheme.outlineVariant),
            Expanded(
              child: _Cell(
                onTap: () => openChat(
                  context,
                  initialDraft: 'Вопрос по товару «${product.name}»: ',
                ),
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.help_outline_rounded, size: 18, color: theme.colorScheme.onSurfaceVariant),
                      const SizedBox(width: 6),
                      Text('Спросить', style: theme.textTheme.bodyMedium),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Cell extends StatelessWidget {
  final VoidCallback onTap;
  final Widget child;

  const _Cell({required this.onTap, required this.child});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
          child: child,
        ),
      ),
    );
  }
}

class _RatingContent extends StatelessWidget {
  final double? rating;
  final int reviewCount;

  const _RatingContent({required this.rating, required this.reviewCount});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (rating == null || reviewCount == 0) {
      return FittedBox(
        fit: BoxFit.scaleDown,
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.star_outline_rounded, size: 18, color: theme.colorScheme.onSurfaceVariant),
            const SizedBox(width: 6),
            Text('Отзывов пока нет', style: theme.textTheme.bodyMedium),
          ],
        ),
      );
    }

    return FittedBox(
      fit: BoxFit.scaleDown,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          // Одна звезда, не пять — как у Ozon/WB в этом компактном виде
          // (полная россыпь звёзд остаётся в StarRating для карточки отзыва
          // и формы оценки). Заодно освобождает место под большие счётчики.
          const Icon(Icons.star_rounded, size: 16, color: Colors.amber),
          const SizedBox(width: 4),
          Text(
            rating!.toStringAsFixed(1).replaceAll('.', ','),
            style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
          ),
          const SizedBox(width: 4),
          Text(
            '· ${formatCount(reviewCount)} ${pluralRu(reviewCount, 'отзыв', 'отзыва', 'отзывов')}',
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}
