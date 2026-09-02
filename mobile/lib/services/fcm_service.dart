// Firebase push для доплаты за перевзвешенный заказ (см. PLAN.md, «По весу —
// перевзвешивание», канал §5.3). Вызывается из AuthState при появлении
// сессии (и при восстановлении из хранилища, и сразу после логина) — там же
// единственное место, где вообще известен sessionToken.
//
// Бэкенд уже готов принять токен: POST /widget/profile/fcm-token
// (см. ProfileController::updateFcmToken), FirebaseService на бэкенде тоже
// написан и молча не отправляет push, пока не задан FIREBASE_CREDENTIALS_PATH.
//
// У каждого флейвора (шопера) — свой Firebase-проект и свой google-services.json
// в android/app/src/<flavor>/, см. mobile/README.md.

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';

import '../data/profile_repository.dart';
import '../firebase_options.dart';

class FcmService {
  /// Запрашивает разрешение, получает device token и регистрирует его на
  /// бэкенде. Слушает onTokenRefresh — токен может смениться в любой момент
  /// (переустановка, смена аккаунта Google Play и т.д.), не только при старте.
  ///
  /// initialize() может быть вызван больше одного раза за сессию приложения
  /// (восстановление сессии при старте, затем повторный логин после выхода) —
  /// Firebase.initializeApp() падает с [core/duplicate-app] при повторном
  /// вызове для default-приложения, поэтому проверяем Firebase.apps.
  static Future<void> initialize(String sessionToken) async {
    if (Firebase.apps.isEmpty) {
      await Firebase.initializeApp(
        options: DefaultFirebaseOptions.currentPlatform,
      );
    }

    final messaging = FirebaseMessaging.instance;
    await messaging.requestPermission();

    final token = await messaging.getToken();
    if (token != null) {
      await _register(sessionToken, token);
    }

    messaging.onTokenRefresh.listen((newToken) => _register(sessionToken, newToken));
  }

  static Future<void> _register(String sessionToken, String token) async {
    try {
      await ProfileRepository.create().updateFcmToken(sessionToken, token);
    } catch (_) {
      // Не смогли зарегистрировать токен — не критично, остальные каналы
      // (Telegram/MAX/email/in-app баннер) всё равно доставят уведомление.
    }
  }
}
