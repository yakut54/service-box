import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/saved_shop.dart';
import '../state/auth_state.dart';
import '../state/cart_state.dart';
import 'account_screen.dart';
import 'cart_screen.dart';
import 'catalog_screen.dart';
import 'orders_screen.dart';
import 'phone_login_screen.dart';
import 'widgets/notification_badge.dart';

/// Корневой экран приложения — нижняя навигация из 4 постоянных разделов:
/// Каталог / Корзина / Заказы / Профиль. Чат вкладкой не делаем — он
/// контекстный (открывается по иконке в шапке каталога), не место, куда
/// возвращаются, в отличие от остальных.
///
/// Раньше (см. AccountButton, до его удаления) на "Заказы"/"Профиль" можно
/// было попасть ТОЛЬКО уже войдя по телефону — оба экрана при вызове без
/// токена просто вечно крутят спиннер (свой _load() тихо ничего не делает
/// без sessionToken). Теперь это постоянные вкладки, доступные в любой
/// момент — [_RequiresLogin] показывает экран входа вместо спиннера, если
/// байер ещё не вошёл.
class HomeShell extends StatefulWidget {
  final SavedShop shop;

  const HomeShell({super.key, required this.shop});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final cartCount = context.watch<CartState>().itemCount;

    return Scaffold(
      body: IndexedStack(
        index: _index,
        children: [
          CatalogScreen(shop: widget.shop),
          const CartScreen(),
          const _RequiresLogin(child: OrdersScreen()),
          const _RequiresLogin(child: AccountScreen()),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          const NavigationDestination(
            icon: Icon(Icons.storefront_outlined),
            selectedIcon: Icon(Icons.storefront),
            label: 'Каталог',
          ),
          NavigationDestination(
            icon: _badged(const Icon(Icons.shopping_cart_outlined), cartCount),
            selectedIcon: _badged(const Icon(Icons.shopping_cart), cartCount),
            label: 'Корзина',
          ),
          const NavigationDestination(
            icon: Icon(Icons.receipt_long_outlined),
            selectedIcon: Icon(Icons.receipt_long),
            label: 'Заказы',
          ),
          const NavigationDestination(
            icon: Icon(Icons.person_outline_rounded),
            selectedIcon: Icon(Icons.person_rounded),
            label: 'Профиль',
          ),
        ],
      ),
    );
  }

  Widget _badged(Widget icon, int count) {
    if (count <= 0) return icon;
    return Stack(
      clipBehavior: Clip.none,
      children: [
        icon,
        Positioned(
          right: -6,
          top: -4,
          child: NotificationBadge(count: count),
        ),
      ],
    );
  }
}

class _RequiresLogin extends StatelessWidget {
  final Widget child;

  const _RequiresLogin({required this.child});

  @override
  Widget build(BuildContext context) {
    final isLoggedIn = context.watch<AuthState>().isLoggedIn;
    return isLoggedIn ? child : const PhoneLoginScreen();
  }
}
