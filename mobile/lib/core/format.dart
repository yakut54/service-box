/// "12345" → "12 345" — разряды пробелом. Общая часть formatRubles (целая
/// часть суммы) и formatCount (счётчики вроде количества отзывов).
String _groupThousands(String digits) {
  final buffer = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) buffer.write(' ');
    buffer.write(digits[i]);
  }
  return buffer.toString();
}

/// Форматирует сумму в рублях с разделителем разрядов: 1234.5 → "1 234,50 ₽".
/// Целые суммы показываются без копеек: 1234 → "1 234 ₽".
String formatRubles(double rubles) {
  final isWhole = rubles == rubles.roundToDouble();
  final value = isWhole ? rubles.toStringAsFixed(0) : rubles.toStringAsFixed(2);
  final parts = value.split('.');
  final formattedInt = _groupThousands(parts[0]);
  return parts.length > 1 ? '$formattedInt,${parts[1]} ₽' : '$formattedInt ₽';
}

/// Целое число с разделителем разрядов, без единицы измерения: 12345 →
/// "12 345". Для счётчиков без верхней границы (отзывы и т.п.), где просто
/// "$n" рано или поздно становится нечитаемой сплошной цепочкой цифр.
String formatCount(int n) => _groupThousands(n.toString());

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

/// Дата+время по образцу Telegram: "Сегодня в 14:32" / "Вчера в 14:32" /
/// "12 июн. в 14:32" (год добавляется только если не текущий) — для
/// просмотра фото в чате на весь экран.
String formatMessageDateTime(DateTime date) {
  const monthsAbbr = [
    'янв.', 'февр.', 'мар.', 'апр.', 'мая', 'июн.',
    'июл.', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.',
  ];
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final target = DateTime(date.year, date.month, date.day);
  final time = '${date.hour.toString().padLeft(2, '0')}:${date.minute.toString().padLeft(2, '0')}';

  if (target == today) return 'Сегодня в $time';
  if (target == today.subtract(const Duration(days: 1))) return 'Вчера в $time';

  final yearSuffix = date.year == now.year ? '' : ' ${date.year}';
  return '${date.day} ${monthsAbbr[date.month - 1]}$yearSuffix в $time';
}

/// Граммы → человекочитаемая строка: мелкие остатки в граммах, крупные — в кг
/// (100000 г нечитаемо, 100 кг — нормально). Тот же формат, что и в админке
/// (admin/src/shared/lib/format.ts formatWeight) — те же товары те же продавцы.
String formatWeight(int grams) =>
    grams >= 1000 ? '${(grams / 1000).toStringAsFixed(grams % 1000 == 0 ? 0 : 2)} кг' : '$grams г';

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
