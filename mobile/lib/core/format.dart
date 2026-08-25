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

/// Дата в формате "5 авг" / "5 авг 2025" (год — только если не текущий).
String formatShortDate(DateTime date) {
  const months = [
    'янв', 'фев', 'мар', 'апр', 'май', 'июн',
    'июл', 'авг', 'сен', 'окт', 'ноя', 'дек',
  ];
  final month = months[date.month - 1];
  final year = date.year == DateTime.now().year ? '' : ' ${date.year}';
  return '${date.day} $month$year';
}

/// Полная дата в родительном падеже: "21 августа 2026" — для отзывов,
/// где короткая "21 авг" (formatShortDate) выглядит слишком сжато.
String formatFullDate(DateTime date) {
  const months = [
    'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
  ];
  return '${date.day} ${months[date.month - 1]} ${date.year}';
}

/// Склонение существительного по числу (1 отзыв / 2 отзыва / 5 отзывов).
/// Стандартное русское правило: 11-14 всегда "many", иначе по последней цифре.
String pluralRu(int n, String one, String few, String many) {
  final mod100 = n % 100;
  if (mod100 >= 11 && mod100 <= 14) return many;
  switch (n % 10) {
    case 1:
      return one;
    case 2:
    case 3:
    case 4:
      return few;
    default:
      return many;
  }
}
