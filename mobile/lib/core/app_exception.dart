/// Виды ошибок, которые могут случиться при поиске магазина.
/// По этому значению UI решает, какую кнопку показать (повторить/ввести код вручную).
enum AppErrorKind {
  invalidCode,
  shopNotFound,
  network,
  server,
  badResponse,
  cameraPermission,
  cameraUnavailable,
  unknown,
}

/// Ошибка с уже готовым текстом на русском — экраны просто показывают
/// message/hint, не решая заново, что писать пользователю.
class AppException implements Exception {
  final AppErrorKind kind;
  final String message;
  final String? hint;

  const AppException(this.kind, this.message, {this.hint});

  factory AppException.invalidCode() => const AppException(
        AppErrorKind.invalidCode,
        'Не похоже на код магазина',
        hint: 'Код состоит из букв и цифр, например FRUIT7',
      );

  factory AppException.shopNotFound() => const AppException(
        AppErrorKind.shopNotFound,
        'Магазин с таким кодом не найден',
        hint: 'Проверьте код или попросите у магазина новый QR-код',
      );

  factory AppException.network() => const AppException(
        AppErrorKind.network,
        'Нет связи с сервером',
        hint: 'Проверьте интернет-соединение и попробуйте ещё раз',
      );

  factory AppException.server() => const AppException(
        AppErrorKind.server,
        'Сервер магазина не отвечает',
        hint: 'Попробуйте немного позже',
      );

  factory AppException.badResponse() => const AppException(
        AppErrorKind.badResponse,
        'Магазин настроен неправильно',
        hint: 'Сообщите об этом магазину',
      );

  factory AppException.cameraPermission() => const AppException(
        AppErrorKind.cameraPermission,
        'Нужен доступ к камере, чтобы сканировать QR-код',
        hint: 'Разрешите доступ в настройках телефона',
      );

  factory AppException.cameraUnavailable() => const AppException(
        AppErrorKind.cameraUnavailable,
        'Камера недоступна на этом устройстве',
        hint: 'Введите код магазина вручную',
      );

  factory AppException.unknown() => const AppException(
        AppErrorKind.unknown,
        'Что-то пошло не так',
        hint: 'Попробуйте ещё раз',
      );
}
