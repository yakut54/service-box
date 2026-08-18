import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'core/app_theme.dart';
import 'data/shop_repository.dart';
import 'data/shops_storage.dart';
import 'state/shops_state.dart';
import 'ui/onboarding_screen.dart';
import 'ui/widgets/shop_avatar.dart';

void main() {
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (_) => ShopsState(ShopRepository.create(), ShopsStorage())..load(),
      child: const _App(),
    );
  }
}

class _App extends StatelessWidget {
  const _App();

  @override
  Widget build(BuildContext context) {
    final shopsState = context.watch<ShopsState>();

    return MaterialApp(
      title: 'Service Box',
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(shopsState.active),
      home: !shopsState.loaded
          ? const Scaffold(body: Center(child: CircularProgressIndicator()))
          : shopsState.isEmpty
              ? const OnboardingScreen()
              : const _HomeScreen(),
    );
  }
}

/// Временная заглушка вместо каталога (М1+) — подтверждает, что магазин
/// выбран и его тема применилась. Экран со списком товаров ещё не начат.
class _HomeScreen extends StatelessWidget {
  const _HomeScreen();

  @override
  Widget build(BuildContext context) {
    final shop = context.watch<ShopsState>().active!;
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
      body: Center(
        child: Text('Каталог магазина «${shop.name}» ещё не готов'),
      ),
    );
  }
}
