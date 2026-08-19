import 'package:flutter/material.dart';
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
import 'state/shop_state.dart';
import 'ui/catalog_screen.dart';
import 'ui/widgets/error_view.dart';

void main() {
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
      ],
      child: const _App(),
    );
  }
}

class _App extends StatelessWidget {
  const _App();

  @override
  Widget build(BuildContext context) {
    final shopState = context.watch<ShopState>();

    return MaterialApp(
      title: FlavorConfig.shopName,
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(shopState.shop),
      home: const _BootScreen(),
    );
  }
}

/// Загружает магазин этой сборки и показывает первый подходящий экран:
/// спиннер → ошибку с повтором → каталог. Байер здесь ничего не выбирает —
/// магазин один, он зашит в приложение (см. FlavorConfig).
class _BootScreen extends StatelessWidget {
  const _BootScreen();

  @override
  Widget build(BuildContext context) {
    final state = context.watch<ShopState>();

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
