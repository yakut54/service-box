# ServiceBox Mobile

Один APK = один шопер. Приложение с рождения знает, чей оно — байер ничего
не выбирает и не сканирует. Какой именно магазин зашивается в сборку,
определяет **flavor** (флейвор), см. `PLAN.md` → «Флот Шоперов».

## Флейворы

Каждый флейвор — это связка:

1. **Identity** (Android Gradle, `android/app/build.gradle.kts`) —
   свой `applicationId`, чтобы APK разных шоперов ставились на телефон
   бок о бок. Имя приложения — в `android/app/src/<flavor>/res/values/strings.xml`.

   Иконка и splash-экран генерируются **по флейворам напрямую**, без
   ручного копирования файлов — оба пакета (`flutter_launcher_icons`,
   `flutter_native_splash`) сами раскладывают результат в
   `android/app/src/<flavor>/res/...` по имени конфиг-файла:
   - `flutter_launcher_icons-<flavor>.yaml` (в корне `mobile/`) —
     `image_path` указывает на PNG в `assets/icon/`.
   - `flutter_native_splash-<flavor>.yaml` — та же иконка на сплошном
     фоне её собственного цвета, а не дефолтный белый-экран-с-иконкой
     от Android 12+.

   Новый флейвор = положить его PNG в `assets/icon/`, скопировать оба
   файла (`flutter_launcher_icons-barbariska.yaml` →
   `flutter_launcher_icons-<flavor>.yaml`, аналогично для splash),
   поменять `image_path`/`color`, один раз прогнать:
   ```bash
   dart run flutter_launcher_icons:main -f flutter_launcher_icons-<flavor>.yaml
   dart run flutter_native_splash:create --all-flavors
   ```
   **После генерации обязательно `git diff` на `android/app/src/main/AndroidManifest.xml`,
   `ios/`, `web/`** — у `flutter_native_splash` есть побочный эффект: он
   переформатирует общий манифест и трогает неиспользуемые в проекте
   платформы (iOS/web). Найдено живым тестом 2026-09-13: переформатирование
   попутно убрало `android:screenOrientation="portrait"` из `<activity>` —
   эти правки нужно откатывать (`git checkout -- android/app/src/main/AndroidManifest.xml
   ios/ web/`), брать из результата только `android/app/src/<flavor>/res/...`.

   Анимированный интро-экран (`lib/ui/splash_intro_screen.dart`) берёт ту
   же иконку через `FlavorConfig.iconAssetPath` (конвенция:
   `assets/icon/<shopCode в нижнем регистре>_app_icon.png`) — отдельно
   ничего указывать не нужно, только соблюсти имя файла.
2. **Brand/runtime** (`mobile/flavors/<flavor>.json`) — код магазина,
   его `api_key`, имя, цвет темы по умолчанию, адрес бэкенда. Читается
   в Dart через `FlavorConfig` (`lib/core/flavor_config.dart`).
3. **Firebase / push** — у каждого флейвора свой Firebase-проект (или своё
   Android-приложение внутри одного проекта) и свой
   `android/app/src/<flavor>/google-services.json`. Плагин
   `com.google.gms.google-services` подхватывает файл по имени флейвора;
   в `android/app/` общего `google-services.json` быть НЕ должно — он стал бы
   fallback-ом на все флейворы и уронил бы сборку чужого шопера («no matching
   client for package name»). В Dart конфиг Firebase не хранится:
   `FcmService` вызывает `Firebase.initializeApp()` без `options`, SDK читает
   ресурсы, сгенерированные Gradle-плагином из `google-services.json` этого
   флейвора. Файла `lib/firebase_options.dart` нет намеренно. Метаданные
   FlutterFire CLI — `mobile/firebase.json`, секция `buildConfigurations.<flavor>`.

Сейчас заведено два флейвора:

- **barbariska** — реальный тестовый магазин (`shop_xdirmgfxyd7u`)
- **fruit** — тестовый флейвор (заглушка-иконка/сплэш, `SHOP_API_KEY` в
  `flavors/fruit.json` — плейсхолдер, не настоящий магазин). Показывает
  механизм генерации иконки/сплэша по флейворам «вживую» — до реальной
  раздачи нужен ещё настоящий `api_key` и `google-services.json`

Приложение всегда ходит на реальный бэкенд (`ApiShopRepository`,
`ApiCatalogRepository`) — моков нет ни в одной сборке.

## Запуск

```bash
flutter run --flavor barbariska --dart-define-from-file=flavors/barbariska.json
```

## Сборка APK

```bash
flutter build apk --flavor barbariska --dart-define-from-file=flavors/barbariska.json
```

## Новый шопер — что заводить

1. `android/app/build.gradle.kts` → новый `create("shop_code")` во `productFlavors` со своим `applicationId`
2. `android/app/src/<shop_code>/res/values/strings.xml` → имя приложения
3. Иконка PNG → `assets/icon/<shop_code>_app_icon.png` + пара конфигов
   `flutter_launcher_icons-<shop_code>.yaml` / `flutter_native_splash-<shop_code>.yaml`
   (скопировать существующие, поменять `image_path`/`color`) → прогнать
   генерацию (см. «Флейворы» выше, включая обязательный `git diff`-чек
   после) — руками файлы в `res/mipmap-*`/`res/drawable*` не копировать
4. `mobile/flavors/<shop_code>.json` → `SHOP_CODE`, `SHOP_API_KEY` (из `shops.api_key`), `SHOP_NAME`, `SHOP_PRIMARY_COLOR`
5. `android/app/src/<shop_code>/google-services.json` → зарегистрировать Android-приложение с этим `applicationId` в Firebase Console, скачать файл сюда (не в `android/app/`). Добавить запись в `mobile/firebase.json` → `platforms.android.buildConfigurations.<shop_code>`. **Файл обязателен для каждого флейвора:** плагин `com.google.gms.google-services` применяется всегда и роняет сборку, если своего `google-services.json` нет. Если конкретному шоперу push реально не нужен — делать применение плагина условным (отдельная задача), а не класть общий файл в `android/app/`
