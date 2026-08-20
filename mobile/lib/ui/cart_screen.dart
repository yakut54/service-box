import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../data/discount_repository.dart';
import '../models/cart_item.dart';
import '../state/cart_state.dart';
import '../state/shop_state.dart';
import 'checkout_screen.dart';
import 'widgets/promo_code_field.dart';

/// Корзина: список добавленных позиций, изменение количества, промокод, сумма.
/// «−» на количестве 1 удаляет позицию целиком (см. _CartQuantityStepper).
class CartScreen extends StatefulWidget {
  const CartScreen({super.key});

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  @override
  void initState() {
    super.initState();
    // Автоскидка (без промокода) проверяется при каждом открытии корзины —
    // не перетирает вручную введённый промокод (см. _checkAutoDiscount).
    WidgetsBinding.instance.addPostFrameCallback((_) => _checkAutoDiscount());
  }

  Future<void> _checkAutoDiscount() async {
    if (!mounted) return;
    final cart = context.read<CartState>();
    if (cart.discount != null || cart.items.isEmpty) return;

    try {
      final found = await DiscountRepository.create().autoApply(
        cart.totalKopecks,
      );
      if (found != null && mounted) {
        context.read<CartState>().setDiscount(found);
      }
    } catch (_) {
      // автоскидка — необязательное удобство, тихо игнорируем сбой
    }
  }

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    final minOrderKopecks = context.watch<ShopState>().shop?.minOrderAmountKopecks ?? 0;

    return Scaffold(
      appBar: AppBar(title: const Text('Корзина')),
      body: cart.items.isEmpty
          ? const _EmptyCart()
          : _CartBody(cart: cart, minOrderKopecks: minOrderKopecks),
    );
  }
}

/// Показывается, пока сумма корзины (до скидки) не дотягивает до
/// минимума заказа — предупреждает заранее, а не после нажатия
/// «Оформить заказ» на экране чекаута.
class _MinOrderHint extends StatelessWidget {
  final double missingRubles;

  const _MinOrderHint({required this.missingRubles});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: scheme.tertiaryContainer.withValues(alpha: 0.6),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Icon(
            Icons.info_outline_rounded,
            size: 18,
            color: scheme.onTertiaryContainer,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'Добавьте товаров ещё на ${formatRubles(missingRubles)} '
              'до минимальной суммы заказа',
              style: theme.textTheme.bodySmall?.copyWith(
                color: scheme.onTertiaryContainer,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _EmptyCart extends StatelessWidget {
  const _EmptyCart();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.shopping_cart_outlined,
              size: 48,
              color: theme.colorScheme.onSurfaceVariant.withValues(alpha: 0.5),
            ),
            const SizedBox(height: 12),
            Text('Корзина пуста', style: theme.textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(
              'Добавьте товары из каталога',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CartBody extends StatelessWidget {
  final CartState cart;
  final int minOrderKopecks;

  const _CartBody({required this.cart, required this.minOrderKopecks});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final discount = cart.discount;
    // Проверяем сумму ДО скидки — так же, как это делает бэкенд
    // (OrderController::store считает минимум по корзине до скидки).
    final missingKopecks = minOrderKopecks - cart.totalKopecks;
    final belowMinimum = missingKopecks > 0;

    return Column(
      children: [
        Expanded(
          child: ListView.separated(
            padding: const EdgeInsets.all(12),
            itemCount: cart.items.length + 1,
            separatorBuilder: (_, _) => const SizedBox(height: 8),
            itemBuilder: (context, index) {
              if (index == cart.items.length) {
                return const PromoCodeField();
              }
              return _CartLineTile(item: cart.items[index]);
            },
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
            child: Column(
              children: [
                if (discount != null) ...[
                  Row(
                    children: [
                      Text('Товары', style: theme.textTheme.bodyMedium),
                      const Spacer(),
                      Text(
                        formatRubles(cart.totalRubles),
                        style: theme.textTheme.bodyMedium,
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Row(
                    children: [
                      Text(
                        discount.name,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: theme.colorScheme.primary,
                        ),
                      ),
                      const Spacer(),
                      Text(
                        '−${formatRubles(discount.amountRubles)}',
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: theme.colorScheme.primary,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                ],
                Row(
                  children: [
                    Text('Итого', style: theme.textTheme.titleMedium),
                    const Spacer(),
                    Text(
                      formatRubles(cart.totalAfterDiscountRubles),
                      style: theme.textTheme.titleLarge?.copyWith(
                        color: theme.colorScheme.primary,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
                if (belowMinimum) ...[
                  const SizedBox(height: 10),
                  _MinOrderHint(missingRubles: missingKopecks / 100),
                ],
                const SizedBox(height: 12),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    onPressed: belowMinimum
                        ? null
                        : () => Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (_) => const CheckoutScreen(),
                            ),
                          ),
                    child: const Text('Оформить заказ'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _CartLineTile extends StatelessWidget {
  final CartItem item;

  const _CartLineTile({required this.item});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final product = item.product;
    final imageUrl = product.imageUrl;

    return Dismissible(
      key: ValueKey(product.id),
      direction: DismissDirection.endToStart,
      onDismissed: (_) => context.read<CartState>().remove(product.id),
      background: Container(
        alignment: Alignment.centerRight,
        padding: const EdgeInsets.symmetric(horizontal: 20),
        decoration: BoxDecoration(
          color: theme.colorScheme.errorContainer,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Icon(
          Icons.delete_outline_rounded,
          color: theme.colorScheme.onErrorContainer,
        ),
      ),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Row(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: SizedBox(
                  width: 56,
                  height: 56,
                  child: (imageUrl != null && imageUrl.isNotEmpty)
                      ? Image.network(
                          imageUrl,
                          fit: BoxFit.cover,
                          errorBuilder: (_, _, _) => _placeholder(theme),
                        )
                      : _placeholder(theme),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      product.name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      formatRubles(product.priceRubles),
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              _CartQuantityStepper(item: item),
            ],
          ),
        ),
      ),
    );
  }

  Widget _placeholder(ThemeData theme) => Container(
    color: theme.colorScheme.surfaceContainerHighest,
    child: Icon(
      Icons.image_outlined,
      size: 20,
      color: theme.colorScheme.onSurfaceVariant.withValues(alpha: 0.4),
    ),
  );
}

/// В отличие от степпера на карточке товара (там минимум 1 — "сколько
/// добавить"), здесь минимум фактически 0: уменьшение с 1 удаляет позицию
/// из корзины целиком, иконка меняется на корзину-с-крестиком для наглядности.
class _CartQuantityStepper extends StatelessWidget {
  final CartItem item;

  const _CartQuantityStepper({required this.item});

  @override
  Widget build(BuildContext context) {
    final physical = item.product.physical;
    final maxQuantity = (physical != null && !physical.allowBackorder)
        ? physical.stockQuantity
        : null;
    final canIncrease = maxQuantity == null || item.quantity < maxQuantity;

    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          IconButton(
            visualDensity: VisualDensity.compact,
            icon: Icon(
              item.quantity == 1
                  ? Icons.delete_outline_rounded
                  : Icons.remove_rounded,
            ),
            onPressed: () => context.read<CartState>().setQuantity(
              item.product,
              item.quantity - 1,
            ),
          ),
          SizedBox(
            width: 24,
            child: Text(
              '${item.quantity}',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
          IconButton(
            visualDensity: VisualDensity.compact,
            icon: const Icon(Icons.add_rounded),
            onPressed: canIncrease
                ? () => context.read<CartState>().setQuantity(
                    item.product,
                    item.quantity + 1,
                  )
                : null,
          ),
        ],
      ),
    );
  }
}
