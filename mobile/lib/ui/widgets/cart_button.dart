import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/cart_state.dart';
import '../cart_screen.dart';
import 'notification_badge.dart';

/// Иконка корзины с бейджем количества — переиспользуется в AppBar каталога
/// и карточки товара, чтобы корзина была доступна с любого экрана покупок.
class CartButton extends StatelessWidget {
  const CartButton({super.key});

  @override
  Widget build(BuildContext context) {
    final itemCount = context.watch<CartState>().itemCount;

    return Stack(
      alignment: Alignment.center,
      children: [
        IconButton(
          icon: const Icon(Icons.shopping_cart_outlined),
          onPressed: () => Navigator.of(
            context,
          ).push(MaterialPageRoute(builder: (_) => const CartScreen())),
        ),
        if (itemCount > 0)
          Positioned(
            right: 6,
            top: 6,
            child: NotificationBadge(count: itemCount),
          ),
      ],
    );
  }
}
