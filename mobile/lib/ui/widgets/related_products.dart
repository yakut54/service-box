import 'package:flutter/material.dart';

import '../../data/catalog_repository.dart';
import '../../models/product.dart';
import '../product_detail_screen.dart';
import 'discount_badge.dart';
import 'product_price_row.dart';

/// Горизонтальная карусель «Похожие товары» — по категории, с исключением
/// уже показанных/добавленных товаров. Общий виджет для карточки товара
/// (одна исключённая позиция — сам товар) и корзины (исключены все товары
/// уже в корзине, категория — по последнему добавленному; пользователь
/// попросил заполнить пустое место в корзине рекомендациями, 2026-09-01, а
/// не плодить вторую копию этой же карусели).
class RelatedProducts extends StatefulWidget {
  final String? categoryId;
  final Set<String> excludeProductIds;

  const RelatedProducts({
    super.key,
    required this.categoryId,
    required this.excludeProductIds,
  });

  @override
  State<RelatedProducts> createState() => _RelatedProductsState();
}

class _RelatedProductsState extends State<RelatedProducts> {
  Future<List<Product>>? _future;

  @override
  void initState() {
    super.initState();
    final categoryId = widget.categoryId;
    if (categoryId != null) {
      _future = CatalogRepository.create()
          .fetchProducts(categoryId: categoryId)
          .then(
            (products) => products
                .where((p) => !widget.excludeProductIds.contains(p.id))
                .toList(),
          );
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_future == null) return const SizedBox.shrink();

    return FutureBuilder<List<Product>>(
      future: _future,
      builder: (context, snapshot) {
        final items = snapshot.data;
        if (items == null || items.isEmpty) return const SizedBox.shrink();

        final theme = Theme.of(context);
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Похожие товары', style: theme.textTheme.titleSmall),
            const SizedBox(height: 10),
            SizedBox(
              height: 190,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: items.length,
                separatorBuilder: (_, _) => const SizedBox(width: 10),
                itemBuilder: (context, index) =>
                    _RelatedProductCard(product: items[index]),
              ),
            ),
          ],
        );
      },
    );
  }
}

class _RelatedProductCard extends StatelessWidget {
  final Product product;

  const _RelatedProductCard({required this.product});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final imageUrl = product.imageUrl;

    return SizedBox(
      width: 130,
      child: Card(
        clipBehavior: Clip.antiAlias,
        margin: EdgeInsets.zero,
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
                              errorBuilder: (_, _, _) => Icon(
                                Icons.image_outlined,
                                size: 28,
                                color: theme.colorScheme.onSurfaceVariant
                                    .withValues(alpha: 0.4),
                              ),
                            )
                          : Icon(
                              Icons.image_outlined,
                              size: 28,
                              color: theme.colorScheme.onSurfaceVariant
                                  .withValues(alpha: 0.4),
                            ),
                    ),
                    if (product.hasDiscount)
                      Positioned(
                        top: 6,
                        right: 6,
                        child: DiscountBadge(percent: product.discountPercent!),
                      ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      product.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 2),
                    ProductPriceRow(
                      product: product,
                      priceStyle: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.primary,
                        fontWeight: FontWeight.bold,
                      ),
                      oldPriceStyle: theme.textTheme.labelSmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                        decoration: TextDecoration.lineThrough,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
