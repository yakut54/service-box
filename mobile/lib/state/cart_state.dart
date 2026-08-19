import 'package:flutter/foundation.dart';

import '../models/cart_item.dart';
import '../models/product.dart';

/// Корзина живёт только в памяти телефона до оформления заказа — на сервер
/// ничего не уходит, пока байер не нажмёт «Оформить заказ» (см. Шаг E).
class CartState extends ChangeNotifier {
  final Map<String, CartItem> _items = {};

  List<CartItem> get items => _items.values.toList(growable: false);

  int get itemCount =>
      _items.values.fold(0, (sum, item) => sum + item.quantity);

  double get totalRubles => _items.values.fold(
    0.0,
    (sum, item) => sum + item.product.priceRubles * item.quantity,
  );

  int quantityOf(String productId) => _items[productId]?.quantity ?? 0;

  void add(Product product, int quantity) {
    if (quantity <= 0) return;
    final current = _items[product.id]?.quantity ?? 0;
    _items[product.id] = CartItem(
      product: product,
      quantity: current + quantity,
    );
    notifyListeners();
  }

  void setQuantity(Product product, int quantity) {
    if (quantity <= 0) {
      _items.remove(product.id);
    } else {
      _items[product.id] = CartItem(product: product, quantity: quantity);
    }
    notifyListeners();
  }

  void remove(String productId) {
    _items.remove(productId);
    notifyListeners();
  }
}
