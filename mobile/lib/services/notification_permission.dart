import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../ui/widgets/app_dialog.dart';

/// Разрешение на показ уведомлений (Android 13 POST_NOTIFICATIONS).
///
/// Не спрашиваем на старте — единственная попытка системного диалога, потрачен-
/// ная впустую, почти необратима. Вместо этого своя шторка-объяснение перед
/// системным диалогом, максимум [_maxPrimers] раза (после первого заказа
/// и/или при первом входе в чат). Дальше — строка «включить» в профиле, ведущая
/// в системные настройки уведомлений приложения (MainActivity.kt).
class NotificationPermission {
  static const _channel = MethodChannel('app.barbariska/notifications');
  static const _primerCountKey = 'notif_primer_count_v1';
  static const _maxPrimers = 2;

  static Future<void> _ensureFirebase() async {
    if (Firebase.apps.isEmpty) {
      await Firebase.initializeApp();
    }
  }

  /// Уведомления реально разрешены системой. false — либо не спрашивали, либо
  /// отказано (Android эти два состояния через getNotificationSettings не
  /// различает — «спрашивали или нет» отслеживаем сами, [_primerCountKey]).
  static Future<bool> isAuthorized() async {
    try {
      await _ensureFirebase();
      final s = await FirebaseMessaging.instance.getNotificationSettings();
      return s.authorizationStatus == AuthorizationStatus.authorized;
    } catch (_) {
      // Нет Google Play Services и т.п. — считаем «не включено», строку в
      // профиле не показываем (см. isEnabledOrUnavailable ниже).
      return false;
    }
  }

  /// Для строки в профиле: показывать её, только когда точно known-выключено.
  /// Если Firebase недоступен вовсе — строку прятать (push здесь не при чём).
  static Future<bool> shouldOfferEnableInProfile() async {
    try {
      await _ensureFirebase();
      final s = await FirebaseMessaging.instance.getNotificationSettings();
      return s.authorizationStatus != AuthorizationStatus.authorized;
    } catch (_) {
      return false;
    }
  }

  /// Открыть системные настройки уведомлений приложения.
  static Future<void> openSystemSettings() async {
    try {
      await _channel.invokeMethod('openNotificationSettings');
    } catch (_) {
      // канал недоступен (iOS / старый движок) — молча
    }
  }

  /// Показать шторку-объяснение и, при согласии, системный диалог.
  /// No-op, если разрешение уже дано, лимит показов исчерпан или Firebase
  /// недоступен. [trigger] — только для будущей аналитики, поведение не меняет.
  static Future<void> maybeShowPrimer(
    BuildContext context, {
    required String trigger,
  }) async {
    try {
      await _ensureFirebase();

      final messaging = FirebaseMessaging.instance;
      final current = await messaging.getNotificationSettings();
      final prefs = await SharedPreferences.getInstance();

      if (current.authorizationStatus == AuthorizationStatus.authorized) {
        await prefs.setInt(_primerCountKey, _maxPrimers);
        return;
      }

      final shown = prefs.getInt(_primerCountKey) ?? 0;
      if (shown >= _maxPrimers) return;

      if (!context.mounted) return;
      final agreed = await showConfirmDialog(
        context,
        title: 'Уведомления о заказе',
        message: 'Будем присылать статус заказа и доставки, а также сообщения '
            'от магазина. Без уведомлений важное легко пропустить.',
        confirmLabel: 'Включить',
        cancelLabel: 'Позже',
        destructive: false,
      );

      await prefs.setInt(_primerCountKey, shown + 1);

      if (agreed) {
        await messaging.requestPermission();
        await prefs.setInt(_primerCountKey, _maxPrimers);
      }
    } catch (_) {
      // Firebase недоступен — другие каналы (Telegram/MAX/чат) всё равно работают.
    }
  }
}
