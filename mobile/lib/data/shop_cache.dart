import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/saved_shop.dart';

/// Кэш последнего успешно загруженного магазина этой сборки.
///
/// Магазин теперь один (зашит в сборку через FlavorConfig), поэтому хранить
/// нужно не список, а один объект — чтобы при следующем запуске без сети
/// показать знакомую тему и название, пока идёт повторный запрос к серверу.
class ShopCache {
  static const _key = 'shop_v2';

  Future<SavedShop?> load() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key);
    if (raw == null) return null;

    try {
      return SavedShop.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      // Битые данные не должны блокировать запуск приложения.
      return null;
    }
  }

  Future<void> save(SavedShop shop) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, jsonEncode(shop.toJson()));
  }
}
