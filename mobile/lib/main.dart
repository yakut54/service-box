import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_native_splash/flutter_native_splash.dart';
import 'package:provider/provider.dart';

import 'core/app_theme.dart';
import 'core/flavor_config.dart';
import 'data/auth_token_store.dart';
import 'data/catalog_repository.dart';
import 'data/shop_cache.dart';
import 'data/shop_repository.dart';
import 'state/auth_state.dart';
import 'state/cart_state.dart';
import 'state/catalog_state.dart';
import 'state/chat_state.dart';
import 'state/shop_state.dart';
import 'ui/catalog_screen.dart';
import 'ui/chat_screen.dart';
import 'ui/splash_intro_screen.dart';
import 'ui/widgets/error_view.dart';

/// Системное «Поделиться» (Android, см. MainActivity.kt) шлёт сюда путь к
/// скопированному файлу — обрабатывается вне дерева виджетов, поэтому нужен
/// доступ к навигатору напрямую, не через BuildContext.
final navigatorKey = GlobalKey<NavigatorState>();
const _shareChannel = MethodChannel('app.barbariska/share');

void main() {
  // Замораживаем нативный сплэш прямо тут — без этого Android сам решает,
  // когда его убрать, и может держать его поверх уже тикающей анимации
  // SplashIntroScreen (см. FlutterNativeSplash.remove() там же). Убираем
  // синхронно с началом анимации, а не когда захочет ОС.
  final widgetsBinding = WidgetsFlutterBinding.ensureInitialized();
  FlutterNativeSplash.preserve(widgetsBinding: widgetsBinding);
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider(
          create: (_) =>
              ShopState(ShopRepository.create(), ShopCache())..load(),
        ),
        ChangeNotifierProvider(
          create: (_) => CatalogState(CatalogRepository.create())..load(),
        ),
        ChangeNotifierProvider(create: (_) => CartState()),
        ChangeNotifierProvider(
          create: (_) => AuthState(AuthTokenStore())..load(),
        ),
        ChangeNotifierProvider(create: (_) => ChatState()),
      ],
      child: const _App(),
    );
  }
}

class _App extends StatefulWidget {
  const _App();

  @override
  State<_App> createState() => _AppState();
}

class _AppState extends State<_App> {
  @override
  void initState() {
    super.initState();
    _shareChannel.setMethodCallHandler((call) async {
      if (call.method != 'sharedImage') return;
      final path = call.arguments as String?;
      if (path == null) return;
      navigatorKey.currentState?.push(
        MaterialPageRoute(builder: (_) => ChatScreen(sharedImagePath: path)),
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    final shopState = context.watch<ShopState>();

    return MaterialApp(
      navigatorKey: navigatorKey,
      title: FlavorConfig.shopName,
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(shopState.shop),
      home: const _BootScreen(),
    );
  }
}

/// Загружает магазин этой сборки и показывает первый подходящий экран:
/// анимированное интро → ошибку с повтором → каталог. Байер здесь ничего
/// не выбирает — магазин один, он зашит в приложение (см. FlavorConfig).
///
/// Интро (SplashIntroScreen) всегда играет целиком хотя бы один раз — даже
/// если магазин загрузился раньше — иначе анимацию будет обрывать на
/// быстром интернете и весь эффект потеряется.
class _BootScreen extends StatefulWidget {
  const _BootScreen();

  @override
  State<_BootScreen> createState() => _BootScreenState();
}

class _BootScreenState extends State<_BootScreen> {
  bool _introDone = false;

  @override
  void initState() {
    super.initState();
    Future.delayed(const Duration(milliseconds: 4000), () {
      if (mounted) setState(() => _introDone = true);
    });
  }

  @override
  Widget build(BuildContext context) {
    final state = context.watch<ShopState>();

    if (!_introDone) {
      return const SplashIntroScreen();
    }

    if (state.shop == null && state.loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    if (state.shop == null && state.error != null) {
      return Scaffold(
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: ErrorView(
                error: state.error!,
                onRetry: () => context.read<ShopState>().refresh(),
              ),
            ),
          ),
        ),
      );
    }

    return CatalogScreen(shop: state.shop!);
  }
}
