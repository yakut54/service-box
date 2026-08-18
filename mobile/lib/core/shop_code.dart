import 'package:flutter/services.dart';

/// Код магазина: буквы и цифры, 4-12 символов.
/// Диапазон шире заявленных бэкендом 6-8 символов специально — чтобы
/// бэкенд мог поменять длину кода, не ломая уже установленные приложения.
final RegExp _validCode = RegExp(r'^[A-Z0-9]{4,12}$');

/// Кириллические буквы, которые на глаз не отличить от латинских.
/// Байер может набрать код на русской раскладке — это нужно прощать.
const Map<String, String> _cyrillicLookalikes = {
  'А': 'A', 'В': 'B', 'Е': 'E', 'К': 'K', 'М': 'M', 'Н': 'H',
  'О': 'O', 'Р': 'P', 'С': 'C', 'Т': 'T', 'У': 'Y', 'Х': 'X',
};

/// Превращает то, что ввёл или отсканировал байер, в чистый код магазина.
///
/// Принимает:
///  - ссылку из QR-кода вида https://<любой домен>/app/FRUIT7 (домен не важен —
///    берём только то, что после /app/)
///  - голый код: fruit7, " FRUIT-7 "
///  - код на русской раскладке: ФРУIТ7
///
/// Возвращает null, если распознать код не удалось.
String? parseShopCode(String raw) {
  var value = raw.trim();
  if (value.isEmpty) return null;

  // Похоже на ссылку — достаём сегмент после /app/.
  if (value.contains('/')) {
    final uri = Uri.tryParse(value.contains('://') ? value : 'https://$value');
    final segments = uri?.pathSegments.where((s) => s.isNotEmpty).toList() ?? const [];
    if (segments.isEmpty) return null;
    final appIndex = segments.indexOf('app');
    value = (appIndex != -1 && appIndex + 1 < segments.length)
        ? segments[appIndex + 1]
        : segments.last;
  }

  // Отрезаем query/fragment, если они каким-то образом остались, и поднимаем регистр.
  value = value.split('?').first.split('#').first.toUpperCase();

  // Кириллические двойники → латиница, затем убираем всё, что не буква/цифра
  // (дефисы, пробелы — люди любят их добавлять "для красоты").
  value = value.split('').map((ch) => _cyrillicLookalikes[ch] ?? ch).join();
  value = value.replaceAll(RegExp(r'[^A-Z0-9]'), '');

  return _validCode.hasMatch(value) ? value : null;
}

/// Форматтер для поля ручного ввода кода: сразу поднимает регистр
/// и не даёт напечатать ничего кроме букв/цифр.
class ShopCodeFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final cleaned = newValue.text
        .toUpperCase()
        .replaceAll(RegExp(r'[^A-ZА-Я0-9]'), '');
    if (cleaned == newValue.text) return newValue;
    return TextEditingValue(
      text: cleaned,
      selection: TextSelection.collapsed(offset: cleaned.length),
    );
  }
}
