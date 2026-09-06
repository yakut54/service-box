import 'product.dart';
import 'product_variant.dart';

/// Позиция в корзине: товар + выбранное количество (штучный товар) либо
/// выбранный вес в граммах (весовой товар — см. PLAN.md, «Развесной товар»).
/// Ровно одно из двух реально используется в зависимости от product.isWeightBased,
/// но оба поля есть всегда, чтобы не городить два разных класса ради одной
/// разницы в способе задания объёма позиции.
///
/// variant — выбранная комбинация размер/цвет (одежда/обувь). null у обычных
/// товаров. Разные варианты одного товара — разные строки корзины (см. key).
class CartItem {
  final Product product;
  final int quantity;
  final int? weightGrams;
  final ProductVariant? variant;

  const CartItem({
    required this.product,
    required this.quantity,
    this.weightGrams,
    this.variant,
  });

  /// Ключ строки корзины: товар + вариант (если есть).
  String get key => cartKey(product.id, variant?.id);

  static String cartKey(String productId, String? variantId) =>
      variantId == null ? productId : '$productId:$variantId';

  /// Цена единицы этой строки в копейках — цена варианта, если он выбран
  /// (скидка считается отдельно на чекауте, сервер пересчитывает).
  int get unitPriceKopecks =>
      variant?.effectivePriceKopecks(product.priceKopecks) ??
      product.priceKopecks;

  /// Стоимость строки — для весового товара priceKopecks это цена за кг,
  /// поэтому quantity здесь не участвует (всегда 1 для весовых строк).
  int get lineTotalKopecks => weightGrams != null
      ? product.priceForWeightGrams(weightGrams!)
      : unitPriceKopecks * quantity;
}
