import '../models/applied_discount.dart';
import '../models/cart_item.dart';
import 'api_client.dart';
import 'discount_repository.dart';

List<Map<String, dynamic>> _itemsPayload(List<CartItem> items) => items
    .map((i) => {
          'product_id': i.product.id,
          if (i.weightGrams != null) 'weight_grams': i.weightGrams else 'quantity': i.quantity,
        })
    .toList();

class ApiDiscountRepository implements DiscountRepository {
  final ApiClient _client = ApiClient();

  @override
  Future<AppliedDiscount> validate(
    String code,
    int cartAmountKopecks,
    List<CartItem> items,
  ) async {
    final json = await _client.post('/widget/discount/validate', {
      'code': code,
      'cart_amount': cartAmountKopecks,
      'items': _itemsPayload(items),
    });
    return AppliedDiscount(
      code: code,
      name: json['name'] as String,
      amountKopecks: (json['discount_amount'] as num).toInt(),
    );
  }

  @override
  Future<AppliedDiscount?> autoApply(
    int cartAmountKopecks,
    List<CartItem> items,
  ) async {
    final json = await _client.post('/widget/discount/auto-apply', {
      'cart_amount': cartAmountKopecks,
      'items': _itemsPayload(items),
    });
    if (json['found'] != true) return null;

    return AppliedDiscount(
      name: json['name'] as String,
      amountKopecks: (json['discount_amount'] as num).toInt(),
    );
  }
}
