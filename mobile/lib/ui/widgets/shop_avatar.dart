import 'package:flutter/material.dart';

import '../../core/app_theme.dart';
import '../../models/saved_shop.dart';

/// Кружок с логотипом магазина или первой буквой названия, если лого нет.
/// Используется и в шапке (ShopSwitcher), и в списке магазинов.
class ShopAvatar extends StatelessWidget {
  final SavedShop shop;
  final double size;

  const ShopAvatar({super.key, required this.shop, this.size = 36});

  @override
  Widget build(BuildContext context) {
    final color = parseHexColor(shop.theme.primaryColor) ??
        Theme.of(context).colorScheme.primary;
    final logoUrl = shop.theme.logoUrl;

    return CircleAvatar(
      radius: size / 2,
      backgroundColor: color.withValues(alpha: 0.15),
      backgroundImage: (logoUrl != null && logoUrl.isNotEmpty)
          ? NetworkImage(logoUrl)
          : null,
      child: (logoUrl == null || logoUrl.isEmpty)
          ? Text(
              shop.name.isNotEmpty ? shop.name[0].toUpperCase() : '?',
              style: TextStyle(
                color: color,
                fontWeight: FontWeight.bold,
                fontSize: size * 0.42,
              ),
            )
          : null,
    );
  }
}
