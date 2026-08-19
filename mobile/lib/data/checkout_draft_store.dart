import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// Черновик формы оформления заказа — сохраняется на каждое изменение,
/// восстанавливается при открытии checkout, чистится после успешной
/// отправки. Повторяет sb_co_progress в веб-виджете
/// (widget/src/components/Checkout.vue: saveProgress/clearProgress),
/// чтобы поведение совпадало — байер не теряет введённые данные,
/// если случайно закрыл приложение на середине оформления.
///
/// Приложение обслуживает один магазин на сборку (см. FlavorConfig),
/// поэтому, в отличие от виджета, ключ не нужно привязывать к shop_id.
class CheckoutDraftStore {
  static const _key = 'checkout_draft_v1';

  Future<Map<String, String>?> load() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key);
    if (raw == null) return null;

    try {
      final decoded = jsonDecode(raw) as Map<String, dynamic>;
      return decoded.map((key, value) => MapEntry(key, value as String));
    } catch (_) {
      return null;
    }
  }

  Future<void> save({
    required String name,
    required String phone,
    required String email,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
      _key,
      jsonEncode({'name': name, 'phone': phone, 'email': email}),
    );
  }

  Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_key);
  }
}
