import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../ui/widgets/app_dialog.dart';

/// Возрастной гейт 18+ (категории с `age_restricted`). Подтверждение хранится
/// на установку в SharedPreferences — спрашиваем один раз. Нейтральные
/// формулировки, без «продающего» тона (research §5.4).
///
/// `confirmed` — ValueNotifier, чтобы карточки товара в сетке разблюривались
/// сразу после подтверждения без ручного setState по всему списку.
class AgeGate {
  AgeGate._();

  static const _key = 'age_confirmed_18';
  static final ValueNotifier<bool> confirmed = ValueNotifier<bool>(false);
  static bool _loaded = false;

  /// Прочитать сохранённое подтверждение. Идемпотентно, тихо переживает
  /// отсутствие/сбой SharedPreferences (приватный режим и т.п.).
  static Future<void> load() async {
    if (_loaded) return;
    _loaded = true;
    try {
      final prefs = await SharedPreferences.getInstance();
      confirmed.value = prefs.getBool(_key) ?? false;
    } catch (_) {
      // остаётся false — гейт просто спросит
    }
  }

  /// Гарантирует, что байер подтвердил 18+. Возвращает true, если можно
  /// показывать контент 18+ (уже подтверждал или подтвердил сейчас), false —
  /// если отказался.
  static Future<bool> ensure(BuildContext context) async {
    await load();
    if (confirmed.value) return true;
    if (!context.mounted) return false;

    final ok = await showConfirmDialog(
      context,
      title: 'Товары 18+',
      message: 'Этот раздел содержит товары для взрослых. '
          'Подтвердите, что вам исполнилось 18 лет.',
      confirmLabel: 'Мне есть 18',
      cancelLabel: 'Выйти',
      destructive: false,
    );

    if (ok) {
      confirmed.value = true;
      try {
        final prefs = await SharedPreferences.getInstance();
        await prefs.setBool(_key, true);
      } catch (_) {}
    }
    return ok;
  }
}
