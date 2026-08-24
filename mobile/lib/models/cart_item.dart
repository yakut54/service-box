import 'product.dart';

/// Позиция в корзине: товар + выбранное количество (штучный товар) либо
/// выбранный вес в граммах (весовой товар — см. PLAN.md, «Развесной товар»).
/// Ровно одно из двух реально используется в зависимости от product.isWeightBased,
/// но оба поля есть всегда, чтобы не городить два разных класса ради одной
/// разницы в способе задания объёма позиции.
class CartItem {
  final Product product;
  final int quantity;
  final int? weightGrams;

  const CartItem({
    required this.product,
    required this.quantity,
    this.weightGrams,
  });

  /// Стоимость строки — для весового товара priceKopecks это цена за кг,
  /// поэтому quantity здесь не участвует (всегда 1 для весовых строк).
  int get lineTotalKopecks => weightGrams != null
      ? product.priceForWeightGrams(weightGrams!)
      : product.priceKopecks * quantity;
}
