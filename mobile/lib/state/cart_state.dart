import 'dart:async';

import 'package:flutter/foundation.dart';

import '../data/discount_repository.dart';
import '../models/applied_discount.dart';
import '../models/cart_item.dart';
import '../models/product.dart';

/// Корзина живёт только в памяти телефона до оформления заказа — на сервер
/// ничего не уходит, пока байер не нажмёт «Оформить заказ» (см. Шаг E).
class CartState extends ChangeNotifier {
  CartState({DiscountRepository? discountRepository})
    : _discountRepository = discountRepository ?? DiscountRepository.create();

  final DiscountRepository _discountRepository;
  final Map<String, CartItem> _items = {};
  AppliedDiscount? _discount;
  Timer? _discountDebounce;
  int _discountRequestId = 0;

  List<CartItem> get items => _items.values.toList(growable: false);

  int get itemCount =>
      _items.values.fold(0, (sum, item) => sum + item.quantity);

  int get totalKopecks => _items.values.fold(
    0,
    (sum, item) => sum + item.product.priceKopecks * item.quantity,
  );

  double get totalRubles => totalKopecks / 100;

  AppliedDiscount? get discount => _discount;

  double get totalAfterDiscountRubles {
    final discount = _discount;
    if (discount == null) return totalRubles;
    final afterDiscount = totalKopecks - discount.amountKopecks;
    return (afterDiscount < 0 ? 0 : afterDiscount) / 100;
  }

  int quantityOf(String productId) => _items[productId]?.quantity ?? 0;

  void add(Product product, int quantity) {
    if (quantity <= 0) return;
    final current = _items[product.id]?.quantity ?? 0;
    _items[product.id] = CartItem(
      product: product,
      quantity: current + quantity,
    );
    notifyListeners();
    _scheduleDiscountRefresh();
  }

  void setQuantity(Product product, int quantity) {
    if (quantity <= 0) {
      _items.remove(product.id);
    } else {
      _items[product.id] = CartItem(product: product, quantity: quantity);
    }
    notifyListeners();
    _scheduleDiscountRefresh();
  }

  void remove(String productId) {
    _items.remove(productId);
    notifyListeners();
    _scheduleDiscountRefresh();
  }

  /// Не триггерит пересчёт скидки сам по себе — иначе снятие промокода
  /// крестиком (PromoCodeField) тут же перетиралось бы обратно авто-скидкой
  /// на этот же кадр, не давая покупателю ввести свой код (баг найден
  /// 2026-08-22: крестик снимает скидку — тут же появляется старая обратно).
  /// «X» на промокоде — осознанный отказ от текущей скидки, а не повод её
  /// тут же переподобрать; авто-скидка сама вернётся при следующем реальном
  /// изменении корзины (см. _scheduleDiscountRefresh в add/setQuantity/remove).
  void setDiscount(AppliedDiscount? discount) {
    _discount = discount;
    notifyListeners();
  }

  /// Вызывается после успешного оформления заказа — товар куплен,
  /// в корзине его больше быть не должно.
  void clear() {
    _discountDebounce?.cancel();
    _items.clear();
    _discount = null;
    notifyListeners();
  }

  @override
  void dispose() {
    _discountDebounce?.cancel();
    super.dispose();
  }

  /// Пересчитывает скидку после любого изменения состава корзины.
  ///
  /// Раньше автоскидка проверялась один раз при открытии экрана корзины
  /// (CartScreen.initState) и потом «замораживалась» — при последующем
  /// добавлении/удалении товаров баннер продолжал показывать скидку от
  /// старого состава корзины, вплоть до несуществующей (например, скидка
  /// «на все конфеты» оставалась в корзине из одних бананов). Деньги при
  /// оформлении заказа считались верно (сервер пересчитывает заново), но
  /// покупатель видел неправильную цифру на экране. Баг найден 2026-08-21.
  ///
  /// Дебаунс на 400мс — чтобы не долбить API на каждый тап степпера.
  void _scheduleDiscountRefresh() {
    _discountDebounce?.cancel();

    if (_items.isEmpty) {
      _discount = null;
      notifyListeners();
      return;
    }

    _discountDebounce = Timer(
      const Duration(milliseconds: 400),
      _refreshDiscount,
    );
  }

  Future<void> _refreshDiscount() async {
    final requestId = ++_discountRequestId;
    final code = _discount?.code;

    try {
      final result = code != null
          ? await _discountRepository.validate(code, totalKopecks, items)
          : await _discountRepository.autoApply(totalKopecks, items);

      if (requestId != _discountRequestId) return; // корзина уже другая

      _discount = result;
      notifyListeners();
    } catch (_) {
      if (requestId != _discountRequestId) return;

      // Промокод перестал подходить (например, товар убрали из корзины) —
      // снимаем. Для авто-скидки сбой сети/сервера тихо игнорируем и
      // оставляем как было — это необязательное удобство, не повод мигать
      // баннером; попробуем снова на следующем изменении корзины.
      if (code != null) {
        _discount = null;
        notifyListeners();
      }
    }
  }
}
