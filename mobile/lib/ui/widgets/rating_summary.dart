import 'package:flutter/material.dart';

import '../../core/format.dart';
import 'star_rating.dart';

/// Компактная строка «★ 4,6 · 12 отзывов» под названием товара — из уже
/// посчитанных на бэкенде product.rating/review_count (только опубликованные
/// отзывы, см. ProductController). Ничего не показывает, если оценок ещё
/// нет — не гонять лишний запрос ради разворачивания полной секции отзывов.
class RatingSummary extends StatelessWidget {
  final double? rating;
  final int reviewCount;

  const RatingSummary({super.key, required this.rating, required this.reviewCount});

  @override
  Widget build(BuildContext context) {
    if (rating == null || reviewCount == 0) return const SizedBox.shrink();

    final theme = Theme.of(context);
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        StarRating(value: rating!, size: 15),
        const SizedBox(width: 6),
        Text(
          rating!.toStringAsFixed(1).replaceAll('.', ','),
          style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
        ),
        const SizedBox(width: 4),
        Text(
          '· $reviewCount ${pluralRu(reviewCount, 'отзыв', 'отзыва', 'отзывов')}',
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      ],
    );
  }
}
