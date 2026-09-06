import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../models/product.dart';
import '../../models/product_variant.dart';
import '../../state/cart_state.dart';
import 'primary_submit_button.dart';
import 'out_of_stock_chip.dart';
import 'weight_cart_control.dart';

/// «В корзину» → степпер +/−, как только товар уже в корзине. Один
/// компонент на две плотности: `compact` — узкая версия для карточки в
/// сетке каталога, обычная — полноширинная версия для карточки товара
/// (см. ProductDetailScreen, где раньше жила своя копия этой же логики —
/// вынесено сюда, чтобы не дублировать степпер в двух местах).
class AddToCartControl extends StatelessWidget {
  final Product product;
  final bool compact;

  /// Выбранный вариант (размер/цвет). Для вариантного товара обязателен —
  /// без него кнопка неактивна («Выберите вариант»).
  final ProductVariant? variant;

  const AddToCartControl({
    super.key,
    required this.product,
    this.compact = false,
    this.variant,
  });

  int? get _maxQuantity {
    if (variant != null) {
      return variant!.allowBackorder ? null : variant!.stockQuantity;
    }
    final physical = product.physical;
    if (physical == null || physical.allowBackorder) return null;
    return physical.stockQuantity;
  }

  @override
  Widget build(BuildContext context) {
    if (product.isWeightBased) {
      return WeightCartControl(product: product, compact: compact);
    }

    final theme = Theme.of(context);

    // Вариантный товар без выбранного варианта — предложить выбрать.
    if (product.hasVariants && variant == null) {
      return PrimarySubmitButton(
        label: 'Выберите вариант',
        icon: Icons.tune_rounded,
        onPressed: null,
      );
    }

    final quantity = context
        .watch<CartState>()
        .quantityOf(product.id, variantId: variant?.id);

    final inStock = variant != null ? variant!.inStock : product.inStock;
    if (!inStock) {
      return OutOfStockChip(compact: compact, theme: theme);
    }

    if (quantity == 0) {
      return _AddButton(
        product: product,
        variant: variant,
        compact: compact,
        theme: theme,
      );
    }

    return _QuantityStepper(
      product: product,
      variant: variant,
      quantity: quantity,
      maxQuantity: _maxQuantity,
      compact: compact,
      theme: theme,
    );
  }
}

class _AddButton extends StatelessWidget {
  final Product product;
  final ProductVariant? variant;
  final bool compact;
  final ThemeData theme;

  const _AddButton({
    required this.product,
    required this.variant,
    required this.compact,
    required this.theme,
  });

  @override
  Widget build(BuildContext context) {
    void onAdd() => context.read<CartState>().add(product, 1, variant: variant);

    if (compact) {
      return SizedBox(
        width: double.infinity,
        height: 36,
        child: Material(
          color: theme.colorScheme.primary,
          borderRadius: BorderRadius.circular(10),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onAdd,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(
                  Icons.add_shopping_cart_rounded,
                  size: 16,
                  color: theme.colorScheme.onPrimary,
                ),
                const SizedBox(width: 6),
                Text(
                  'В корзину',
                  style: theme.textTheme.labelMedium?.copyWith(
                    color: theme.colorScheme.onPrimary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return PrimarySubmitButton(
      label: 'В корзину',
      icon: Icons.add_shopping_cart_rounded,
      onPressed: onAdd,
    );
  }
}

/// «−» на количестве 1 удаляет позицию — та же семантика, что и в корзине.
class _QuantityStepper extends StatelessWidget {
  final Product product;
  final ProductVariant? variant;
  final int quantity;
  final int? maxQuantity;
  final bool compact;
  final ThemeData theme;

  const _QuantityStepper({
    required this.product,
    required this.variant,
    required this.quantity,
    required this.maxQuantity,
    required this.compact,
    required this.theme,
  });

  @override
  Widget build(BuildContext context) {
    final canIncrease = maxQuantity == null || quantity < maxQuantity!;
    final cart = context.read<CartState>();

    if (compact) {
      return SizedBox(
        width: double.infinity,
        height: 36,
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: theme.colorScheme.primary,
            borderRadius: BorderRadius.circular(10),
          ),
          child: Row(
            children: [
              _CompactStepButton(
                icon: quantity == 1
                    ? Icons.delete_outline_rounded
                    : Icons.remove_rounded,
                onTap: () => cart.setQuantity(product, quantity - 1, variant: variant),
                theme: theme,
              ),
              Expanded(
                child: Text(
                  '$quantity',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.labelLarge?.copyWith(
                    color: theme.colorScheme.onPrimary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              _CompactStepButton(
                icon: Icons.add_rounded,
                onTap: canIncrease
                    ? () => cart.setQuantity(product, quantity + 1, variant: variant)
                    : null,
                theme: theme,
              ),
            ],
          ),
        ),
      );
    }

    return Container(
      decoration: BoxDecoration(
        color: theme.colorScheme.primaryContainer,
        borderRadius: BorderRadius.circular(12),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: Row(
        children: [
          IconButton(
            onPressed: () => cart.setQuantity(product, quantity - 1, variant: variant),
            icon: Icon(
              quantity == 1
                  ? Icons.delete_outline_rounded
                  : Icons.remove_rounded,
            ),
          ),
          Expanded(
            child: Text(
              'В корзине: $quantity шт.',
              textAlign: TextAlign.center,
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          IconButton(
            onPressed: canIncrease
                ? () => cart.setQuantity(product, quantity + 1, variant: variant)
                : null,
            icon: const Icon(Icons.add_rounded),
          ),
        ],
      ),
    );
  }
}

class _CompactStepButton extends StatelessWidget {
  final IconData icon;
  final VoidCallback? onTap;
  final ThemeData theme;

  const _CompactStepButton({
    required this.icon,
    required this.onTap,
    required this.theme,
  });

  @override
  Widget build(BuildContext context) {
    final disabled = onTap == null;
    return SizedBox(
      width: 32,
      height: 36,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(10),
          child: Icon(
            icon,
            size: 16,
            color: disabled
                ? theme.colorScheme.onPrimary.withValues(alpha: 0.35)
                : theme.colorScheme.onPrimary,
          ),
        ),
      ),
    );
  }
}
