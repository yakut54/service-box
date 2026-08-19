import 'product.dart';

/// Позиция в корзине: товар + выбранное количество.
class CartItem {
  final Product product;
  final int quantity;

  const CartItem({required this.product, required this.quantity});
}
