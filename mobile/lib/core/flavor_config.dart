/// Конфиг конкретной сборки (флейвора) — один APK = один шопер.
///
/// Значения зашиваются на этапе сборки через `--dart-define-from-file`
/// (см. `mobile/flavors/*.json` и команды в `mobile/README.md`). Байер
/// никогда не вводит код магазина — приложение с рождения знает, чей оно.
///
/// Слои конфига (см. PLAN.md, МФ2):
///  - identity — applicationId и иконка настраиваются в Android Gradle
///    (`android/app/build.gradle.kts`, `android/app/src/<flavor>/res`),
///    сюда не попадают — это не Dart-код.
///  - brand    — код и стартовое имя/цвет магазина (до первого ответа сервера).
///  - runtime  — куда стучаться (адрес бэкенда).
class FlavorConfig {
  const FlavorConfig._();

  /// Код магазина, зашитый в сборку. Пусто быть не должно ни в одной
  /// реальной сборке — пустая строка означает "забыли передать dart-define".
  static const String shopCode = String.fromEnvironment('SHOP_CODE');

  /// shops.api_key — то же значение уходит в заголовок X-Shop-ID.
  static const String shopApiKey = String.fromEnvironment('SHOP_API_KEY');

  /// Имя магазина для показа, пока не пришёл ответ сервера.
  static const String shopName = String.fromEnvironment(
    'SHOP_NAME',
    defaultValue: 'Магазин',
  );

  /// HEX-цвет темы по умолчанию — до первой загрузки реального widget_config.
  static const String shopPrimaryColor = String.fromEnvironment(
    'SHOP_PRIMARY_COLOR',
    defaultValue: '#2563EB',
  );

  /// Базовый адрес бэкенда.
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://yakut54.ru',
  );

  /// Публичный ключ Reverb-приложения (WebSocket, чат в реальном времени) —
  /// один на весь бэкенд, не per-shop (все магазины сидят на одном Laravel
  /// с одним Reverb-сервером), поэтому дефолта достаточно без правки каждого
  /// flavors/*.json. Секретный ключ (REVERB_APP_SECRET) сюда никогда не
  /// попадает — он только на сервере, для подписи авторизации канала.
  static const String reverbAppKey = String.fromEnvironment(
    'REVERB_APP_KEY',
    defaultValue: 'b4trd04insm1nhqtfzpn',
  );
}
