import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// Снятие плашек чата из шторки уведомлений.
///
/// Сами уведомления о новых сообщениях рисует система по FCM notification-блоку
/// (канал `chat`, тег `chat-<threadId>` — см. бэкенд SendChatPush). Этот
/// класс их НЕ публикует, только гасит:
///  - пришёл тихий push `type=chat_deleted` (сообщение удалили в админке);
///  - байер открыл этот чат — старая плашка больше не нужна.
///
/// flutter_local_notifications используется исключительно ради
/// getActiveNotifications()/cancel() — в firebase_messaging такого API нет.
class ChatNotifications {
  ChatNotifications._();

  static final FlutterLocalNotificationsPlugin _plugin =
      FlutterLocalNotificationsPlugin();
  static bool _inited = false;

  static Future<void> _ensureInit() async {
    if (_inited) return;
    // Инициализация нужна и в фоновом изоляте (onBackgroundMessage). Иконку
    // указываем формально — уведомления отсюда не показываются.
    await _plugin.initialize(
      const InitializationSettings(
        android: AndroidInitializationSettings('@mipmap/ic_launcher'),
      ),
    );
    _inited = true;
  }

  /// Обработать входящий remote-message: гасим тред, если это `chat_deleted`.
  static Future<void> handleRemoteMessage(RemoteMessage message) async {
    if (message.data['type'] != 'chat_deleted') return;
    final threadId = message.data['thread_id'] as String?;
    if (threadId != null && threadId.isNotEmpty) {
      await dismissThread(threadId);
    }
  }

  /// Убрать из шторки все плашки треда (тег `chat-<threadId>`).
  static Future<void> dismissThread(String threadId) async {
    final tag = 'chat-$threadId';
    try {
      await _ensureInit();
      final active = await _plugin.getActiveNotifications();
      for (final n in active) {
        if (n.tag == tag && n.id != null) {
          await _plugin.cancel(n.id!, tag: n.tag);
        }
      }
    } catch (_) {
      // гашение уведомлений не критично — молча пропускаем
    }
  }
}

/// Фоновый обработчик FCM (приложение свёрнуто/не на переднем плане).
/// Обязан быть top-level и помечен vm:entry-point — вызывается в отдельном
/// изоляте, где нет ничего из основного рантайма.
@pragma('vm:entry-point')
Future<void> chatFirebaseBackgroundHandler(RemoteMessage message) async {
  try {
    if (Firebase.apps.isEmpty) {
      await Firebase.initializeApp();
    }
  } catch (_) {
    return;
  }
  await ChatNotifications.handleRemoteMessage(message);
}
