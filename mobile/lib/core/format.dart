/// Форматирует сумму в рублях с разделителем разрядов: 1234.5 → "1 234,50 ₽".
/// Целые суммы показываются без копеек: 1234 → "1 234 ₽".
String formatRubles(double rubles) {
  final isWhole = rubles == rubles.roundToDouble();
  final value = isWhole ? rubles.toStringAsFixed(0) : rubles.toStringAsFixed(2);
  final parts = value.split('.');

  final buffer = StringBuffer();
  final intPart = parts[0];
  for (var i = 0; i < intPart.length; i++) {
    if (i > 0 && (intPart.length - i) % 3 == 0) buffer.write(' ');
    buffer.write(intPart[i]);
  }

  final formattedInt = buffer.toString();
  return parts.length > 1 ? '$formattedInt,${parts[1]} ₽' : '$formattedInt ₽';
}
