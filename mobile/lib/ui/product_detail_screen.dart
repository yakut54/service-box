import 'package:flutter/material.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../data/catalog_repository.dart';
import '../models/product.dart';
import '../services/age_gate.dart';
import 'widgets/add_to_cart_control.dart';
import 'widgets/cart_button.dart';
import 'widgets/discount_badge.dart';
import 'widgets/error_view.dart';
import 'widgets/product_price_row.dart';
import 'widgets/product_rating_ask_row.dart';
import 'widgets/related_products.dart';
import 'widgets/size_chart_sheet.dart';
import 'widgets/spec_list.dart';

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
    _maybeAgeGate();
  }

  /// Открыли карточку 18+ товара (напр. по deep-link) — спрашиваем возраст
  /// поверх, отказ закрывает экран.
  void _maybeAgeGate() {
    _future
        .then((product) async {
          if (!mounted ||
              !product.categoryAgeRestricted ||
              AgeGate.confirmed.value) {
            return;
          }
          final ok = await AgeGate.ensure(context);
          if (!ok && mounted) Navigator.of(context).pop();
        })
        .catchError((_) {}); // ошибку загрузки покажет FutureBuilder
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
    final discountEndsAt = product.discountEndsAt;

    // Структурные поля физ-товара + произвольные характеристики из админки —
    // один блок «Характеристики» (Baymard принцип 6: сухие факты отдельно
    // от описания).
    final physical = product.physical;
    final specRows = <(String, String)>[
      if (physical?.weightGrams != null) ('Вес', '${physical!.weightGrams} г'),
      if ((physical?.dimensions ?? '').isNotEmpty) ('Размер', physical!.dimensions!),
      if ((physical?.color ?? '').isNotEmpty) ('Цвет', physical!.color!),
      if ((physical?.unitsPerPack ?? 0) > 1)
        ('В упаковке', '${physical!.unitsPerPack} ${product.unitLabel}'),
      for (final a in product.attributes) (a.label, a.value),
      if ((physical?.markingCode ?? '').isNotEmpty)
        ('Маркировка', physical!.markingCode!),
    ];

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
              // Цена сразу под названием (не после рейтинга) — по образцу
              // Ozon: это самое важное, что решает, читать ли дальше. Порядок
              // блоков согласован с пользователем 2026-09-01 (было
              // название→рейтинг→цена→описание→характеристики, стало
              // название→цена→рейтинг→характеристики→описание).
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  ProductPriceRow(
                    product: product,
                    priceStyle: theme.textTheme.titleLarge?.copyWith(
                      color: theme.colorScheme.primary,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  if (product.hasDiscount) ...[
                    const SizedBox(width: 8),
                    DiscountBadge(percent: product.discountPercent!),
                  ],
                ],
              ),
              // Цена за единицу упаковки — Baymard принцип 3 (сравнимость).
              if (product.unitPriceKopecks != null) ...[
                const SizedBox(height: 4),
                Text(
                  '${formatRubles(product.unitPriceKopecks! / 100)}/${product.unitLabel}',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
              if (discountEndsAt != null) ...[
                const SizedBox(height: 4),
                Text(
                  'Акция до ${formatShortDate(discountEndsAt)}',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              ],
              if (!inStock) ...[
                const SizedBox(height: 8),
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
              ],
              // Возврату не подлежит (гигиена, интимные товары) — рядом с
              // ценой, не мелким текстом внизу (research §3, принцип 5).
              if (product.categoryNoReturn) ...[
                const SizedBox(height: 8),
                Row(
                  children: [
                    Icon(
                      Icons.block_rounded,
                      size: 16,
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                    const SizedBox(width: 6),
                    Text(
                      'Возврату не подлежит',
                      style: theme.textTheme.labelMedium?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ],
              const SizedBox(height: 12),
              ProductRatingAskRow(product: product),
              if (product.sizeChart?.isUsable ?? false) ...[
                const SizedBox(height: 4),
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () =>
                        SizeChartSheet.show(context, product.sizeChart!),
                    icon: const Icon(Icons.straighten_rounded, size: 18),
                    label: const Text('Таблица размеров'),
                    style: TextButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 8),
                      visualDensity: VisualDensity.compact,
                    ),
                  ),
                ),
              ],
              // Характеристики (сухие факты) — перед описанием (текст), не
              // после, тоже часть того же согласованного порядка.
              if (specRows.isNotEmpty) ...[
                const SizedBox(height: 16),
                SpecList(rows: specRows),
              ],
              if (product.description != null &&
                  product.description!.isNotEmpty) ...[
                const SizedBox(height: 16),
                Text(product.description!, style: theme.textTheme.bodyMedium),
              ],
              const SizedBox(height: 24),
              RelatedProducts(
                categoryId: product.categoryId,
                excludeProductIds: {product.id},
              ),
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


