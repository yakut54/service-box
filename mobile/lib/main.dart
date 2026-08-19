import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'core/app_theme.dart';
import 'core/flavor_config.dart';
import 'data/shop_cache.dart';
import 'data/shop_repository.dart';
import 'state/shop_state.dart';
import 'ui/widgets/error_view.dart';
import 'ui/widgets/shop_avatar.dart';

void main() {
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (_) => ShopState(ShopRepository.create(), ShopCache())..load(),
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

    return const _CatalogScreen();
  }
}

/// Временная заглушка вместо каталога (М1+) — подтверждает, что магазин
/// загрузился и его тема применилась. Экран со списком товаров ещё не начат.
class _CatalogScreen extends StatelessWidget {
  const _CatalogScreen();

  @override
  Widget build(BuildContext context) {
    final state = context.watch<ShopState>();
    final shop = state.shop!;

    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            ShopAvatar(shop: shop, size: 28),
            const SizedBox(width: 12),
            Text(shop.name),
          ],
        ),
      ),
      body: RefreshIndicator(
        onRefresh: () => context.read<ShopState>().refresh(),
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            Center(
              child: Text('Каталог магазина «${shop.name}» ещё не готов'),
            ),
          ],
        ),
      ),
    );
  }
}
