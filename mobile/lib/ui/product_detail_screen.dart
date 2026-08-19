import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../data/catalog_repository.dart';
import '../models/product.dart';
import '../state/cart_state.dart';
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
  int _quantity = 1;

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
      appBar: AppBar(),
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

          return _ProductDetailBody(
            product: snapshot.data!,
            quantity: _quantity,
            onQuantityChanged: (value) => setState(() => _quantity = value),
          );
        },
      ),
    );
  }
}

class _ProductDetailBody extends StatelessWidget {
  final Product product;
  final int quantity;
  final ValueChanged<int> onQuantityChanged;

  const _ProductDetailBody({
    required this.product,
    required this.quantity,
    required this.onQuantityChanged,
  });

  int? get _maxQuantity {
    final physical = product.physical;
    if (physical == null || physical.allowBackorder) return null;
    return physical.stockQuantity;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final imageUrl = product.imageUrl;
    final maxQuantity = _maxQuantity;
    final inStock = product.inStock;

    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
            children: [
              AspectRatio(
                aspectRatio: 1,
                child: Container(
                  decoration: BoxDecoration(
                    color: theme.colorScheme.surfaceContainerHighest,
                    borderRadius: BorderRadius.circular(16),
                  ),
                  clipBehavior: Clip.antiAlias,
                  child: (imageUrl != null && imageUrl.isNotEmpty)
                      ? Image.network(
                          imageUrl,
                          fit: BoxFit.cover,
                          errorBuilder: (_, _, _) => _placeholderIcon(theme),
                        )
                      : _placeholderIcon(theme),
                ),
              ),
              const SizedBox(height: 16),
              Text(product.name, style: theme.textTheme.headlineSmall),
              const SizedBox(height: 8),
              Text(
                formatRubles(product.priceRubles),
                style: theme.textTheme.titleLarge?.copyWith(
                  color: theme.colorScheme.primary,
                  fontWeight: FontWeight.bold,
                ),
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
            ],
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
            child: Row(
              children: [
                if (inStock) ...[
                  _QuantityStepper(
                    quantity: quantity,
                    maxQuantity: maxQuantity,
                    onChanged: onQuantityChanged,
                  ),
                  const SizedBox(width: 12),
                ],
                Expanded(
                  child: FilledButton(
                    onPressed: inStock
                        ? () {
                            context.read<CartState>().add(product, quantity);
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                content: Text(
                                  'Добавлено в корзину: $quantity шт.',
                                ),
                              ),
                            );
                          }
                        : null,
                    child: Text(inStock ? 'В корзину' : 'Нет в наличии'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _placeholderIcon(ThemeData theme) => Icon(
    Icons.image_outlined,
    size: 48,
    color: theme.colorScheme.onSurfaceVariant.withValues(alpha: 0.4),
  );
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

class _QuantityStepper extends StatelessWidget {
  final int quantity;
  final int? maxQuantity;
  final ValueChanged<int> onChanged;

  const _QuantityStepper({
    required this.quantity,
    required this.maxQuantity,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    final canIncrease = maxQuantity == null || quantity < maxQuantity!;

    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          IconButton(
            onPressed: quantity > 1 ? () => onChanged(quantity - 1) : null,
            icon: const Icon(Icons.remove_rounded),
          ),
          SizedBox(
            width: 28,
            child: Text(
              '$quantity',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium,
            ),
          ),
          IconButton(
            onPressed: canIncrease ? () => onChanged(quantity + 1) : null,
            icon: const Icon(Icons.add_rounded),
          ),
        ],
      ),
    );
  }
}
