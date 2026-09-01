import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../data/cart_swipe_hint_store.dart';
import '../models/cart_item.dart';
import '../state/cart_state.dart';
import '../state/shop_state.dart';
import 'checkout_screen.dart';
import 'product_detail_screen.dart';
import 'widgets/primary_submit_button.dart';
import 'widgets/promo_code_field.dart';
import 'widgets/related_products.dart';
import 'widgets/weight_cart_control.dart';

/// Корзина: список добавленных позиций, изменение количества, промокод, сумма.
/// «−» на количестве 1 удаляет позицию целиком (см. _CartQuantityStepper).
/// Промокод/автоскидка пересчитываются в CartState на каждое изменение
/// корзины (см. CartState._scheduleDiscountRefresh) — этому экрану о них
/// заботиться не нужно.
class CartScreen extends StatefulWidget {
  const CartScreen({super.key});

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  final _hintStore = CartSwipeHintStore();
  bool _showSwipeHint = false;

  @override
  void initState() {
    super.initState();
    _loadHintFlag();
  }

  Future<void> _loadHintFlag() async {
    final dismissed = await _hintStore.isDismissed();
    if (!mounted || dismissed) return;
    setState(() => _showSwipeHint = true);
  }

  void _closeSwipeHint({required bool rememberChoice}) {
    setState(() => _showSwipeHint = false);
    if (rememberChoice) _hintStore.dismissForever();
  }

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    final minOrderKopecks = context.watch<ShopState>().shop?.minOrderAmountKopecks ?? 0;

    return Scaffold(
      appBar: AppBar(title: const Text('Корзина')),
      body: cart.items.isEmpty
          ? const _EmptyCart()
          : _CartBody(
              cart: cart,
              minOrderKopecks: minOrderKopecks,
              showSwipeHint: _showSwipeHint,
              onCloseSwipeHint: _closeSwipeHint,
            ),
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

/// Подсказка про свайп-удаление — единственная подсказка о жесте в корзине
/// (иконка-корзина на карточке раньше дублировала свайп явной кнопкой,
/// убрана в пользу этого баннера). Крестик закрывает только на эту сессию;
/// подсказка не появится больше, только если отмечен чекбокс.
class _SwipeHintBanner extends StatefulWidget {
  final void Function({required bool rememberChoice}) onClose;

  const _SwipeHintBanner({required this.onClose});

  @override
  State<_SwipeHintBanner> createState() => _SwipeHintBannerState();
}

class _SwipeHintBannerState extends State<_SwipeHintBanner> {
  bool _dontShowAgain = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 8, 8),
      decoration: BoxDecoration(
        color: scheme.primaryContainer.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.swipe_left_alt_rounded, size: 20, color: scheme.primary),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Чтобы удалить товар из корзины, смахните его влево',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onPrimaryContainer,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ),
              IconButton(
                onPressed: () => widget.onClose(rememberChoice: _dontShowAgain),
                visualDensity: VisualDensity.compact,
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(minWidth: 28, minHeight: 28),
                icon: Icon(Icons.close_rounded, size: 18, color: scheme.onPrimaryContainer),
              ),
            ],
          ),
          InkWell(
            borderRadius: BorderRadius.circular(6),
            onTap: () => setState(() => _dontShowAgain = !_dontShowAgain),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Checkbox(
                    value: _dontShowAgain,
                    visualDensity: VisualDensity.compact,
                    materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    onChanged: (v) => setState(() => _dontShowAgain = v ?? false),
                  ),
                  const SizedBox(width: 4),
                  Text(
                    'Больше не показывать',
                    style: theme.textTheme.bodySmall?.copyWith(color: scheme.onPrimaryContainer),
                  ),
                ],
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
  final bool showSwipeHint;
  final void Function({required bool rememberChoice}) onCloseSwipeHint;

  const _CartBody({
    required this.cart,
    required this.minOrderKopecks,
    required this.showSwipeHint,
    required this.onCloseSwipeHint,
  });

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
          // «Похожие товары» прижаты к низу списка: при малом числе позиций
          // пустое место остаётся над каруселью, а не под ней перед «Итого».
          // ConstrainedBox(minHeight) + IntrinsicHeight — приём для прижатия
          // футера внутри скролла: IntrinsicHeight даёт Column тугую (не
          // бесконечную) высоту, поэтому Expanded внутри реально получает
          // свободное место, а не падает с ошибкой unbounded height. Когда
          // контент выше экрана — свободного места нет, ведёт себя как
          // обычный скролл.
          child: LayoutBuilder(
            builder: (context, constraints) {
              return SingleChildScrollView(
                padding: const EdgeInsets.all(12),
                child: ConstrainedBox(
                  constraints: BoxConstraints(minHeight: constraints.maxHeight),
                  child: IntrinsicHeight(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (showSwipeHint) ...[
                          _SwipeHintBanner(onClose: onCloseSwipeHint),
                          const SizedBox(height: 8),
                        ],
                        for (final item in cart.items) ...[
                          _CartLineTile(item: item),
                          const SizedBox(height: 8),
                        ],
                        const PromoCodeField(),
                        Expanded(
                          child: Align(
                            alignment: Alignment.bottomCenter,
                            child: Padding(
                              padding: const EdgeInsets.only(top: 8),
                              // CartScreen.build() уже уводит на _EmptyCart(),
                              // как только cart.items пустеет — .last сюда
                              // попасть с пустым списком не должен, но
                              // isNotEmpty дешевле, чем гадать про порядок
                              // перерисовок между виджетами (похожий баг с
                              // чужим контекстом в шторке веса найден рядом
                              // же, 2026-09-01).
                              child: RelatedProducts(
                                categoryId: cart.items.isNotEmpty ? cart.items.last.product.categoryId : null,
                                excludeProductIds: cart.items.map((i) => i.product.id).toSet(),
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              );
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
                PrimarySubmitButton(
                  label: 'Оформить заказ',
                  onPressed: belowMinimum
                      ? null
                      : () => Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => const CheckoutScreen(),
                          ),
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
                    child: InkWell(
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => ProductDetailScreen(productId: product.id),
                        ),
                      ),
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
                          item.weightGrams != null
                              ? '${formatRubles(product.priceRubles)}/кг'
                              : formatRubles(product.priceRubles),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  item.weightGrams != null
                      ? SizedBox(
                          width: 110,
                          child: WeightCartControl(
                            product: product,
                            compact: true,
                            outlined: true,
                          ),
                        )
                      : _CartQuantityStepper(item: item),
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
