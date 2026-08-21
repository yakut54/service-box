import 'package:flutter/material.dart';

import '../../models/product.dart';
import '../product_detail_screen.dart';
import 'add_to_cart_control.dart';
import 'discount_badge.dart';
import 'product_price_row.dart';

/// Карточка товара в сетке каталога: фото, название, цена, бейдж наличия.
/// Тап открывает карточку товара (ProductDetailScreen).
class ProductCard extends StatelessWidget {
  final Product product;

  const ProductCard({super.key, required this.product});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final imageUrl = product.imageUrl;

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => ProductDetailScreen(productId: product.id),
          ),
        ),
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
            Padding(
              padding: const EdgeInsets.fromLTRB(10, 10, 10, 0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    product.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 4),
                  ProductPriceRow(product: product),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(6, 14, 6, 10),
              child: AddToCartControl(product: product, compact: true),
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
}
