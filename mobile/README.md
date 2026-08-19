# ServiceBox Mobile

Один APK = один шопер. Приложение с рождения знает, чей оно — байер ничего
не выбирает и не сканирует. Какой именно магазин зашивается в сборку,
определяет **flavor** (флейвор), см. `PLAN.md` → «Флот Шоперов».

## Флейворы

Каждый флейвор — это связка:

1. **Identity** (Android Gradle, `android/app/build.gradle.kts`) —
   свой `applicationId`, чтобы APK разных шоперов ставились на телефон
   бок о бок. Имя приложения — в `android/app/src/<flavor>/res/values/strings.xml`.
   Иконка — `android/app/src/<flavor>/res/mipmap-*/ic_launcher.png`.
   Генерируется из одного PNG через `flutter_launcher_icons`: положить
   исходник в `assets/icon/`, указать его в `flutter_launcher_icons:`
   (pubspec.yaml) → `dart run flutter_launcher_icons` → результат
   появляется в `android/app/src/main/res/mipmap-*/` → руками скопировать
   в `android/app/src/<flavor>/res/mipmap-*/` и вернуть `main/` обратно
   (`git checkout`), чтобы у следующего флейвора не было чужой иконки
   как дефолта.
   Splash-экран (первый экран при запуске, до отрисовки Flutter) — та же
   иконка на сплошном фоне её собственного цвета (пипеткой с PNG, см.
   `flutter_native_splash:` в pubspec.yaml), а не дефолтный
   белый-экран-с-иконкой от Android 12+. Генерируется через
   `dart run flutter_native_splash:create` → результат в
   `android/app/src/main/res/drawable*`, `values-v31`, `values-night-v31`
   → руками перенести в `android/app/src/<flavor>/res/...` и вернуть
   `main/` (те же файлы, что и с иконкой) — `values/styles.xml` и
   `values-night/styles.xml` трогать не нужно, там нет цвета, только
   структурные флаги, общие для всех флейворов.
2. **Brand/runtime** (`mobile/flavors/<flavor>.json`) — код магазина,
   его `api_key`, имя, цвет темы по умолчанию, адрес бэкенда. Читается
   в Dart через `FlavorConfig` (`lib/core/flavor_config.dart`).

Сейчас заведён один флейвор:

- **barbariska** — реальный тестовый магазин (`shop_xdirmgfxyd7u`)

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
3. `android/app/src/<shop_code>/res/mipmap-*/ic_launcher.png` → иконка (когда будет)
4. `mobile/flavors/<shop_code>.json` → `SHOP_CODE`, `SHOP_API_KEY` (из `shops.api_key`), `SHOP_NAME`, `SHOP_PRIMARY_COLOR`
