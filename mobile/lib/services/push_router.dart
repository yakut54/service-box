import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';

import '../ui/chat_screen.dart';
import '../ui/order_detail_screen.dart';

/// Куда вести байера по push и что показывать, пока приложение открыто.
///
///  - холодный старт тапом по уведомлению → getInitialMessage (один раз);
///  - тап по уведомлению из фона → onMessageOpenedApp;
///  - приложение на переднем плане → onMessage: системная плашка НЕ появляется,
///    решаем сами — открытый тред чата глушим (WS уже доставил), иначе
///    показываем in-app баннер с переходом.
///
/// Регистрируется в main.dart ДО первого кадра, иначе холодный старт теряет
/// initial message.
class PushRouter {
  static bool _attached = false;
  static GlobalKey<NavigatorState>? _navKey;
  static Future<void> Function()? _waitAuthReady;

  static Future<void> attach(
    GlobalKey<NavigatorState> navKey,
    Future<void> Function() waitAuthReady,
  ) async {
    if (_attached) return;
    _attached = true;
    _navKey = navKey;
    _waitAuthReady = waitAuthReady;

    try {
      if (Firebase.apps.isEmpty) {
        await Firebase.initializeApp();
      }
    } catch (_) {
      return; // нет Google Play Services и т.п.
    }

    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) _openFrom(initial.data);

    FirebaseMessaging.onMessageOpenedApp.listen((m) => _openFrom(m.data));
    FirebaseMessaging.onMessage.listen(_onForeground);
  }

  /// Тап по уведомлению → сразу на нужный экран.
  static Future<void> _openFrom(Map<String, dynamic> data) async {
    final route = _routeFor(data);
    if (route == null) return;
    await _waitAuthReady?.call();
    _navKey?.currentState?.push(route);
  }

  /// Приложение открыто, push пришёл в onMessage (Android плашку не рисует).
  static void _onForeground(RemoteMessage m) {
    final data = m.data;
    final type = data['type'];

    // Открыт именно этот чат — ничего не делаем, сообщение и так в ленте.
    if (type == 'chat' && chatScreenOpen) return;

    final messenger = _navKey?.currentState != null
        ? ScaffoldMessenger.maybeOf(_navKey!.currentContext!)
        : null;
    if (messenger == null) return;

    final n = m.notification;
    final title = n?.title ?? data['title'] as String? ?? 'Уведомление';
    final body = n?.body ?? data['body'] as String? ?? '';

    messenger
      ..clearSnackBars()
      ..showSnackBar(
        SnackBar(
          content: Text(body.isEmpty ? title : '$title: $body',
              maxLines: 2, overflow: TextOverflow.ellipsis),
          action: SnackBarAction(
            label: 'Открыть',
            onPressed: () => _openFrom(data),
          ),
          duration: const Duration(seconds: 5),
        ),
      );
  }

  static Route<void>? _routeFor(Map<String, dynamic> data) {
    final type = data['type'];

    if (type == 'chat') {
      return MaterialPageRoute(builder: (_) => const ChatScreen());
    }

    if (type == 'order_status' || type == 'order_surcharge') {
      final orderId = data['order_id'] as String?;
      if (orderId == null || orderId.isEmpty) return null;
      // Экран сам грузит заказ и показывает ErrorView, если его больше нет —
      // протухший диплинк не роняет приложение.
      return MaterialPageRoute(builder: (_) => OrderDetailScreen(orderId: orderId));
    }

    return null;
  }
}
