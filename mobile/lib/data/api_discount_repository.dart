import '../models/applied_discount.dart';
import 'api_client.dart';
import 'discount_repository.dart';

class ApiDiscountRepository implements DiscountRepository {
  final ApiClient _client = ApiClient();

  @override
  Future<AppliedDiscount> validate(String code, int cartAmountKopecks) async {
    final json = await _client.post('/widget/discount/validate', {
      'code': code,
      'cart_amount': cartAmountKopecks,
    });
    return AppliedDiscount(
      code: code,
      name: json['name'] as String,
      amountKopecks: (json['discount_amount'] as num).toInt(),
    );
  }

  @override
  Future<AppliedDiscount?> autoApply(int cartAmountKopecks) async {
    final json = await _client.post('/widget/discount/auto-apply', {
      'cart_amount': cartAmountKopecks,
    });
    if (json['found'] != true) return null;

    return AppliedDiscount(
      name: json['name'] as String,
      amountKopecks: (json['discount_amount'] as num).toInt(),
    );
  }
}
