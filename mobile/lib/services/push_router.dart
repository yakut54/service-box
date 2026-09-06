import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';

import '../ui/order_detail_screen.dart';

/// Куда вести байера по тапу на push.
///
/// Два входа: приложение было убито и открыто тапом по уведомлению
/// (getInitialMessage — проверяется один раз на старте) и приложение было в
/// фоне (onMessageOpenedApp — подписка). Foreground-сообщения (onMessage) —
/// это Шаг 5 (in-app), здесь не трогаем.
///
/// Хендлер регистрируется в main.dart ДО отрисовки первого экрана, иначе
/// холодный старт теряет initial message.
class PushRouter {
  static bool _attached = false;

  /// [waitAuthReady] — future, который резолвится, когда AuthState.load()
  /// закончил читать сессию (экран заказа ей пользуется).
  static Future<void> attach(
    GlobalKey<NavigatorState> navKey,
    Future<void> Function() waitAuthReady,
  ) async {
    if (_attached) return;
    _attached = true;

    try {
      if (Firebase.apps.isEmpty) {
        await Firebase.initializeApp();
      }
    } catch (_) {
      return; // нет Google Play Services и т.п.
    }

    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) {
      _route(navKey, waitAuthReady, initial.data);
    }

    FirebaseMessaging.onMessageOpenedApp.listen(
      (m) => _route(navKey, waitAuthReady, m.data),
    );
  }

  static Future<void> _route(
    GlobalKey<NavigatorState> navKey,
    Future<void> Function() waitAuthReady,
    Map<String, dynamic> data,
  ) async {
    final type = data['type'];
    final orderId = data['order_id'] as String?;

    if ((type == 'order_status' || type == 'order_surcharge') &&
        orderId != null &&
        orderId.isNotEmpty) {
      await waitAuthReady();
      // Экран сам грузит заказ и показывает ErrorView, если его больше нет —
      // протухший диплинк не роняет приложение.
      navKey.currentState?.push(
        MaterialPageRoute(builder: (_) => OrderDetailScreen(orderId: orderId)),
      );
    }
  }
}
