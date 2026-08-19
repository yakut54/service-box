import '../models/applied_discount.dart';
import 'api_discount_repository.dart';

/// Промокоды и автоскидки (см. /widget/discount/validate, /auto-apply).
/// Клиентский расчёт — только превью для показа байеру; реальная сумма
/// всегда пересчитывается на сервере при создании заказа заново.
abstract class DiscountRepository {
  /// Бросает AppException с текстом причины, если промокод недействителен.
  Future<AppliedDiscount> validate(String code, int cartAmountKopecks);

  /// null — подходящей автоскидки нет (это не ошибка).
  Future<AppliedDiscount?> autoApply(int cartAmountKopecks);

  factory DiscountRepository.create() => ApiDiscountRepository();
}
