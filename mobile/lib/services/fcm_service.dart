// Firebase push для доплаты за перевзвешенный заказ (см. PLAN.md, «По весу —
// перевзвешивание», канал §5.3). НЕ ПОДКЛЮЧЕНО к main.dart — сознательно:
// firebase_core требует google-services.json конкретного Firebase-проекта
// (Project Settings → Service Accounts → Android app) и подключения плагина
// com.google.gms.google-services в android/app/build.gradle, иначе сборка
// APK падает на этапе Gradle для ВСЕХ флейворов, не только текущего. Пока
// этого файла нет — данный сервис написан, но не вызывается нигде.
//
// Что нужно от шопера, чтобы включить push (тот же паттерн, что был с SMTP):
//   1. Создать проект в Firebase Console, добавить Android-приложение с
//      applicationId флейвора (см. mobile/README.md), скачать google-services.json.
//   2. Прислать этот файл — кладём в mobile/android/app/src/<flavor>/google-services.json.
//   3. Подключаем plugin 'com.google.gms.google-services' в
//      android/build.gradle (classpath) и android/app/build.gradle (apply plugin).
//   4. В main.dart, после WidgetsFlutterBinding.ensureInitialized(), добавляем
//      `await FcmService.initialize(sessionToken)`.
//
// Бэкенд уже готов принять токен: POST /widget/profile/fcm-token
// (см. ProfileController::updateFcmToken), FirebaseService на бэкенде тоже
// написан и молча не отправляет push, пока не задан FIREBASE_CREDENTIALS_PATH.

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';

import '../data/profile_repository.dart';

class FcmService {
  /// Запрашивает разрешение, получает device token и регистрирует его на
  /// бэкенде. Слушает onTokenRefresh — токен может смениться в любой момент
  /// (переустановка, смена аккаунта Google Play и т.д.), не только при старте.
  static Future<void> initialize(String sessionToken) async {
    await Firebase.initializeApp();

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
