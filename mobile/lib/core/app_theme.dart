import 'package:flutter/material.dart';

import '../models/saved_shop.dart';
import 'flavor_config.dart';

/// Разбирает HEX-цвет в форматах '#16A34A', '16A34A', '#0F0' (короткая запись).
/// На любой мусор возвращает null — тогда используется цвет по умолчанию.
Color? parseHexColor(String? hex) {
  if (hex == null) return null;
  var value = hex.trim().replaceAll('#', '').toUpperCase();
  if (value.length == 3) {
    value = value.split('').map((c) => '$c$c').join();
  }
  if (value.length == 6) {
    value = 'FF$value'; // добавляем непрозрачность
  }
  if (value.length != 8) return null;
  final parsed = int.tryParse(value, radix: 16);
  return parsed == null ? null : Color(parsed);
}

/// Строит тему приложения по цвету магазина этой сборки.
/// Пока магазин ещё не загрузился — используется его цвет по умолчанию
/// из FlavorConfig (зашит на этапе сборки), чтобы не мигать нейтральной темой.
ThemeData buildAppTheme(SavedShop? shop) {
  final theme = shop?.theme;
  final seedColor =
      parseHexColor(theme?.primaryColor) ??
      parseHexColor(FlavorConfig.shopPrimaryColor) ??
      const Color(0xFF2563EB);
  final isDark = theme?.preset == 'dark';
  final radius = (theme?.borderRadius ?? 8).toDouble();

  return ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(
      seedColor: seedColor,
      brightness: isDark ? Brightness.dark : Brightness.light,
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radius),
        ),
        minimumSize: const Size.fromHeight(52),
      ),
    ),
    cardTheme: CardThemeData(
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(radius),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(radius)),
    ),
  );
}
