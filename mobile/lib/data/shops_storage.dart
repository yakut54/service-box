import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/saved_shop.dart';

/// Хранит список магазинов байера и код активного магазина в телефоне.
/// Версия в ключе (_v1) — если формат данных когда-нибудь поменяется,
/// старые записи просто не найдутся, а не сломают запуск приложения.
class ShopsStorage {
  static const _shopsKey = 'shops_v1';
  static const _activeKey = 'active_shop_v1';

  Future<List<SavedShop>> loadShops() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_shopsKey);
    if (raw == null) return [];

    try {
      final list = jsonDecode(raw) as List;
      return list
          .map((item) => SavedShop.fromJson(item as Map<String, dynamic>))
          .toList();
    } catch (_) {
      // Битые данные не должны блокировать запуск приложения —
      // просто считаем, что сохранённых магазинов нет.
      return [];
    }
  }

  Future<void> saveShops(List<SavedShop> shops) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = jsonEncode(shops.map((s) => s.toJson()).toList());
    await prefs.setString(_shopsKey, raw);
  }

  Future<String?> loadActiveCode() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_activeKey);
  }

  Future<void> saveActiveCode(String? code) async {
    final prefs = await SharedPreferences.getInstance();
    if (code == null) {
      await prefs.remove(_activeKey);
    } else {
      await prefs.setString(_activeKey, code);
    }
  }
}
