import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/format.dart';
import '../../state/cart_state.dart';
import '../cart_screen.dart';

/// Высота полосы, которую нужно резервировать под прокручиваемым контентом,
/// чтобы последний ряд товаров не оказался под баром (см. CatalogScreen).
const double miniCartBarReservedHeight = 88;

/// Плавающая полоса «N товаров · сумма · Перейти в корзину» — выезжает
/// снизу поверх каталога, когда в корзине что-то есть, и уезжает обратно,
/// когда корзина опустела. Экономит путь к корзине по сравнению с иконкой
/// в углу шапки (см. Wildberries/Ozon). Виджет всегда смонтирован —
/// показ/скрытие идёт через анимацию, а не пересоздание дерева, чтобы
/// выезд и уезд были плавными в обе стороны.
class MiniCartBar extends StatelessWidget {
  const MiniCartBar({super.key});

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    final visible = cart.itemCount > 0;
    final theme = Theme.of(context);

    return IgnorePointer(
      ignoring: !visible,
      child: AnimatedSlide(
        duration: const Duration(milliseconds: 220),
        curve: Curves.easeOutCubic,
        offset: visible ? Offset.zero : const Offset(0, 1.2),
        child: AnimatedOpacity(
          duration: const Duration(milliseconds: 180),
          opacity: visible ? 1 : 0,
          child: SafeArea(
            minimum: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: Material(
              color: theme.colorScheme.primary,
              borderRadius: BorderRadius.circular(16),
              elevation: 6,
              shadowColor: Colors.black45,
              clipBehavior: Clip.antiAlias,
              child: InkWell(
                onTap: () => Navigator.of(
                  context,
                ).push(MaterialPageRoute(builder: (_) => const CartScreen())),
                child: Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 14,
                    vertical: 12,
                  ),
                  child: Row(
                    children: [
                      _CountBadge(count: cart.itemCount),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          formatRubles(cart.totalRubles),
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.titleMedium?.copyWith(
                            color: theme.colorScheme.onPrimary,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                      Text(
                        'Перейти в корзину',
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: theme.colorScheme.onPrimary,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(width: 4),
                      Icon(
                        Icons.arrow_forward_rounded,
                        color: theme.colorScheme.onPrimary,
                        size: 18,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _CountBadge extends StatelessWidget {
  final int count;

  const _CountBadge({required this.count});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: theme.colorScheme.onPrimary.withValues(alpha: 0.18),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        '$count',
        style: theme.textTheme.labelLarge?.copyWith(
          color: theme.colorScheme.onPrimary,
          fontWeight: FontWeight.bold,
        ),
      ),
    );
  }
}
