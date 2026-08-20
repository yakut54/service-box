import 'package:flutter/services.dart';

/// Маска телефона +7 (XXX) XXX-XX-XX — повторяет `formatPhone` из
/// `widget/src/lib/utils.ts`, чтобы поведение совпадало с веб-виджетом.
/// '8' в начале нормализуется в '7', код страны добавляется сам, лишние
/// цифры сверх 11 просто не вводятся — ограничение работает на вводе,
/// а не только в тексте ошибки после отправки.
class RussianPhoneInputFormatter extends TextInputFormatter {
  const RussianPhoneInputFormatter();

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final cursorIndex = newValue.selection.end.clamp(0, newValue.text.length);
    var digitsBeforeCursor = _digitsOnly(
      newValue.text.substring(0, cursorIndex),
    );
    var digits = _digitsOnly(newValue.text);

    if (digits.isNotEmpty && digits[0] == '8') {
      digits = '7${digits.substring(1)}';
    }
    if (digits.isNotEmpty && digits[0] != '7') {
      digits = '7$digits';
      digitsBeforeCursor = '7$digitsBeforeCursor';
    }
    if (digits.length > 11) digits = digits.substring(0, 11);
    if (digitsBeforeCursor.length > digits.length) {
      digitsBeforeCursor = digits;
    }

    final formatted = format(digits);
    final cursor = _cursorForDigitCount(formatted, digitsBeforeCursor.length);

    return TextEditingValue(
      text: formatted,
      selection: TextSelection.collapsed(offset: cursor),
    );
  }

  static String _digitsOnly(String value) =>
      value.replaceAll(RegExp(r'\D'), '');

  /// Публичный доступ к маске — нужен, чтобы форматировать телефон при
  /// автозаполнении из профиля (уже нормализованный "+7XXXXXXXXXX" с
  /// бэкенда), не только на вводе через сам форматтер.
  static String format(String digits) {
    if (digits.isEmpty) return '';
    final buffer = StringBuffer('+7');
    if (digits.length <= 1) return buffer.toString();

    buffer.write(' (${digits.substring(1, digits.length.clamp(1, 4))}');
    if (digits.length > 4) {
      buffer.write(') ${digits.substring(4, digits.length.clamp(4, 7))}');
    }
    if (digits.length > 7) {
      buffer.write('-${digits.substring(7, digits.length.clamp(7, 9))}');
    }
    if (digits.length > 9) {
      buffer.write('-${digits.substring(9, digits.length.clamp(9, 11))}');
    }
    return buffer.toString();
  }

  static int _cursorForDigitCount(String formatted, int digitCount) {
    if (digitCount <= 0) return formatted.isEmpty ? 0 : 2; // сразу после "+7"
    var count = 0;
    for (var i = 0; i < formatted.length; i++) {
      if (RegExp(r'\d').hasMatch(formatted[i])) {
        count++;
        if (count == digitCount) return i + 1;
      }
    }
    return formatted.length;
  }
}
