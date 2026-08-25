import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// Локальная память «на этот товар отзыв уже отправлен» — бэкенд не даёт
/// способа спросить «оставлял ли я отзыв на товар X» (нет ни is_published
/// в ответе POST, ни отдельного /widget/reviews/mine — см. PLAN.md), а
/// заводить такой эндпоинт ради этого не входит в объём портирования.
///
/// Честно ограничено: не переживёт переустановку приложения и не узнает,
/// опубликовал ли магазин отзыв на самом деле — только подавляет повторный
/// показ формы на этом устройстве. Привязано к номеру телефона, а не
/// глобально — чтобы выход и вход под другим номером на одном телефоне не
/// путал чужую историю отзывов.
class ReviewedProductsStore {
  static const _key = 'reviewed_products_v1';

  Future<bool> isReviewed(String phone, String productId) async {
    final map = await _load();
    return (map[phone] ?? const []).contains(productId);
  }

  Future<void> markReviewed(String phone, String productId) async {
    final map = await _load();
    final list = {...(map[phone] ?? const []), productId}.toList();
    map[phone] = list;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, jsonEncode(map));
  }

  Future<Map<String, List<String>>> _load() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key);
    if (raw == null) return {};

    try {
      final decoded = jsonDecode(raw) as Map<String, dynamic>;
      return decoded.map(
        (key, value) => MapEntry(key, (value as List<dynamic>).cast<String>()),
      );
    } catch (_) {
      return {};
    }
  }
}
