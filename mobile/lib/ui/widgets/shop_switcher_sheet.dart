import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/shops_state.dart';
import '../onboarding_screen.dart';
import 'shop_avatar.dart';

/// М0.6 — список сохранённых магазинов байера: тап переключает активный,
/// «Добавить магазин» открывает онбординг поверх текущего экрана.
Future<void> showShopSwitcher(BuildContext context) {
  return showModalBottomSheet(
    context: context,
    showDragHandle: true,
    builder: (_) => const ShopSwitcherSheet(),
  );
}

class ShopSwitcherSheet extends StatelessWidget {
  const ShopSwitcherSheet({super.key});

  @override
  Widget build(BuildContext context) {
    final state = context.watch<ShopsState>();
    return SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final shop in state.shops)
            ListTile(
              leading: ShopAvatar(shop: shop),
              title: Text(shop.name),
              trailing: shop.appCode == state.active?.appCode
                  ? const Icon(Icons.check_rounded)
                  : null,
              onTap: () {
                Navigator.of(context).pop();
                context.read<ShopsState>().select(shop.appCode);
              },
            ),
          ListTile(
            leading: const Icon(Icons.add_rounded),
            title: const Text('Добавить магазин'),
            onTap: () {
              Navigator.of(context).pop();
              Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (_) => const OnboardingScreen(isAdditional: true),
                ),
              );
            },
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }
}
