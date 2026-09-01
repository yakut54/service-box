import 'package:shared_preferences/shared_preferences.dart';

/// Флаг «подсказка про смахивание в корзине больше не нужна» — ставится,
/// только когда байер сам отмечает чекбокс «Больше не показывать» при
/// закрытии подсказки; простое закрытие крестиком ничего не сохраняет,
/// подсказка появится снова при следующем открытии корзины.
class CartSwipeHintStore {
  static const _key = 'cart_swipe_hint_dismissed_v1';

  Future<bool> isDismissed() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_key) ?? false;
  }

  Future<void> dismissForever() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_key, true);
  }
}
