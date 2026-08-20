import '../models/applied_discount.dart';
import '../models/cart_item.dart';
import 'api_discount_repository.dart';

/// Промокоды и автоскидки (см. /widget/discount/validate, /auto-apply).
/// Клиентский расчёт — только превью для показа байеру; реальная сумма
/// всегда пересчитывается на сервере при создании заказа заново.
///
/// [items] обязателен для правильного расчёта product/category-scoped
/// скидок — без него бэкенд не может проверить, что в корзине реально
/// лежит нужный товар/категория, и раньше тихо считал скидку «на один
/// товар» как скидку на всю корзину (баг, найден 2026-08-20).
abstract class DiscountRepository {
  /// Бросает AppException с текстом причины, если промокод недействителен.
  Future<AppliedDiscount> validate(
    String code,
    int cartAmountKopecks,
    List<CartItem> items,
  );

  /// null — подходящей автоскидки нет (это не ошибка).
  Future<AppliedDiscount?> autoApply(
    int cartAmountKopecks,
    List<CartItem> items,
  );

  factory DiscountRepository.create() => ApiDiscountRepository();
}
