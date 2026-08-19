// Валидаторы форм — единые для всего приложения, чтобы поведение совпадало
// с веб-виджетом (см. widget/src/lib/utils.ts: isPhoneValid/isEmailValid).

/// Ровно 11 цифр, начинается с 7 (после нормализации 8→7 в RussianPhoneInputFormatter).
bool isRussianPhoneValid(String value) {
  final digits = value.replaceAll(RegExp(r'\D'), '');
  return digits.length == 11 && digits.startsWith('7');
}

/// Тот же самый regex, что и в веб-виджете — сознательно не строже и не мягче.
final RegExp _emailPattern = RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$');

bool isEmailValid(String value) => _emailPattern.hasMatch(value.trim());
