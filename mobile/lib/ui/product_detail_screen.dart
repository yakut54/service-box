import 'package:flutter/material.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../data/catalog_repository.dart';
import '../models/product.dart';
import 'widgets/add_to_cart_control.dart';
import 'widgets/cart_button.dart';
import 'widgets/discount_badge.dart';
import 'widgets/error_view.dart';

/// Карточка товара: GET /widget/products/{id}. Открывается тапом по
/// карточке в каталоге (см. ProductCard).
class ProductDetailScreen extends StatefulWidget {
  final String productId;

  const ProductDetailScreen({super.key, required this.productId});

  @override
  State<ProductDetailScreen> createState() => _ProductDetailScreenState();
}

class _ProductDetailScreenState extends State<ProductDetailScreen> {
  late Future<Product> _future;

  @override
  void initState() {
    super.initState();
    _future = CatalogRepository.create().fetchProduct(widget.productId);
  }

  void _retry() {
    setState(() {
      _future = CatalogRepository.create().fetchProduct(widget.productId);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(actions: const [CartButton()]),
      body: FutureBuilder<Product>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            final error = snapshot.error is AppException
                ? snapshot.error as AppException
                : AppException.unknown();
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: ErrorView(error: error, onRetry: _retry),
              ),
            );
          }

          return _ProductDetailBody(product: snapshot.data!);
        },
      ),
    );
  }
}

class _ProductDetailBody extends StatelessWidget {
  final Product product;

  const _ProductDetailBody({required this.product});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final inStock = product.inStock;
    final showOldPrice =
        product.comparePriceKopecks != null &&
        product.comparePriceKopecks! > product.priceKopecks;

    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
            children: [
              _ProductGallery(images: product.galleryImages),
              const SizedBox(height: 16),
              Text(product.name, style: theme.textTheme.headlineSmall),
              const SizedBox(height: 8),
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  Text(
                    formatRubles(product.priceRubles),
                    style: theme.textTheme.titleLarge?.copyWith(
                      color: theme.colorScheme.primary,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  if (showOldPrice) ...[
                    const SizedBox(width: 8),
                    Text(
                      formatRubles(product.comparePriceKopecks! / 100),
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                        decoration: TextDecoration.lineThrough,
                      ),
                    ),
                  ],
                  if (product.hasDiscount) ...[
                    const SizedBox(width: 8),
                    DiscountBadge(percent: product.discountPercent!),
                  ],
                ],
              ),
              const SizedBox(height: 8),
              if (!inStock)
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 6,
                  ),
                  decoration: BoxDecoration(
                    color: theme.colorScheme.errorContainer,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    'Нет в наличии',
                    style: theme.textTheme.labelMedium?.copyWith(
                      color: theme.colorScheme.onErrorContainer,
                    ),
                  ),
                ),
              if (product.description != null &&
                  product.description!.isNotEmpty) ...[
                const SizedBox(height: 16),
                Text(product.description!, style: theme.textTheme.bodyMedium),
              ],
              if (product.physical != null) ...[
                const SizedBox(height: 16),
                _CharacteristicsList(physical: product.physical!),
              ],
              const SizedBox(height: 24),
              _RelatedProducts(product: product),
            ],
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
            child: AddToCartControl(product: product),
          ),
        ),
      ],
    );
  }
}

/// Обложка + доп. фото (М1). Одно фото — статичная картинка, как раньше;
/// несколько — свайп через PageView с точками-индикатором снизу.
class _ProductGallery extends StatefulWidget {
  final List<String> images;

  const _ProductGallery({required this.images});

  @override
  State<_ProductGallery> createState() => _ProductGalleryState();
}

class _ProductGalleryState extends State<_ProductGallery> {
  final _pageController = PageController();
  int _page = 0;

  @override
  void dispose() {
    _pageController.dispose();
    super.dispose();
  }

  Widget _placeholderIcon(ThemeData theme) => Icon(
    Icons.image_outlined,
    size: 48,
    color: theme.colorScheme.onSurfaceVariant.withValues(alpha: 0.4),
  );

  Widget _photo(String url, ThemeData theme) => Image.network(
    url,
    fit: BoxFit.cover,
    errorBuilder: (_, _, _) => _placeholderIcon(theme),
  );

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final images = widget.images;

    return AspectRatio(
      aspectRatio: 1,
      child: Container(
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(16),
        ),
        clipBehavior: Clip.antiAlias,
        child: images.isEmpty
            ? _placeholderIcon(theme)
            : images.length == 1
            ? _photo(images.first, theme)
            : Stack(
                alignment: Alignment.bottomCenter,
                children: [
                  PageView.builder(
                    controller: _pageController,
                    itemCount: images.length,
                    onPageChanged: (i) => setState(() => _page = i),
                    itemBuilder: (_, i) => _photo(images[i], theme),
                  ),
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        for (var i = 0; i < images.length; i++)
                          AnimatedContainer(
                            duration: const Duration(milliseconds: 200),
                            margin: const EdgeInsets.symmetric(horizontal: 3),
                            width: i == _page ? 16 : 6,
                            height: 6,
                            decoration: BoxDecoration(
                              color: i == _page
                                  ? Colors.white
                                  : Colors.white.withValues(alpha: 0.5),
                              borderRadius: BorderRadius.circular(3),
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

class _CharacteristicsList extends StatelessWidget {
  final ProductPhysical physical;

  const _CharacteristicsList({required this.physical});

  @override
  Widget build(BuildContext context) {
    final rows = <(String, String)>[
      if (physical.weightGrams != null) ('Вес', '${physical.weightGrams} г'),
      if (physical.dimensions != null && physical.dimensions!.isNotEmpty)
        ('Размер', physical.dimensions!),
      if (physical.color != null && physical.color!.isNotEmpty)
        ('Цвет', physical.color!),
    ];

    if (rows.isEmpty) return const SizedBox.shrink();

    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Характеристики', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        for (final (label, value) in rows)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Row(
              children: [
                SizedBox(
                  width: 100,
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

/// Другие товары из той же категории — горизонтальная лента под основным
/// контентом карточки. Молча ничего не показывает, если у товара нет
/// категории, категория пуста (кроме самого товара) или запрос не удался —
/// это вспомогательный блок, не стоит пугать байера ошибкой ради него.
class _RelatedProducts extends StatefulWidget {
  final Product product;

  const _RelatedProducts({required this.product});

  @override
  State<_RelatedProducts> createState() => _RelatedProductsState();
}

class _RelatedProductsState extends State<_RelatedProducts> {
  Future<List<Product>>? _future;

  @override
  void initState() {
    super.initState();
    final categoryId = widget.product.categoryId;
    if (categoryId != null) {
      _future = CatalogRepository.create()
          .fetchProducts(categoryId: categoryId)
          .then(
            (products) =>
                products.where((p) => p.id != widget.product.id).toList(),
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
                    Text(
                      formatRubles(product.priceRubles),
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.primary,
                        fontWeight: FontWeight.bold,
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
