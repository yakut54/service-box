/// Виды ошибок, которые могут случиться при загрузке магазина этой сборки.
/// По этому значению UI решает, какую иконку и кнопку показать.
enum AppErrorKind {
  shopNotFound,
  notFound,
  network,
  server,
  badResponse,
  unknown,
}

/// Ошибка с уже готовым текстом на русском — экраны просто показывают
/// message/hint, не решая заново, что писать пользователю.
class AppException implements Exception {
  final AppErrorKind kind;
  final String message;
  final String? hint;

  const AppException(this.kind, this.message, {this.hint});

  factory AppException.shopNotFound() => const AppException(
    AppErrorKind.shopNotFound,
    'Магазин не найден',
    hint:
        'Сообщите об этом магазину — возможно, приложение настроено неправильно',
  );

  /// Generic 404 из ApiClient — конкретный репозиторий сам решает, нужно ли
  /// перевести это в более точную ошибку (см. ApiShopRepository.shopNotFound).
  factory AppException.notFound() => const AppException(
    AppErrorKind.notFound,
    'Не найдено',
    hint: 'Возможно, это больше не актуально',
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

  /// [message] — если сервер прислал конкретный текст ошибки (см.
  /// ApiClient._tryExtractMessage), показываем его вместо общей фразы.
  factory AppException.badResponse([String? message]) => AppException(
    AppErrorKind.badResponse,
    message ?? 'Магазин настроен неправильно',
    hint: message == null ? 'Сообщите об этом магазину' : null,
  );

  factory AppException.unknown() => const AppException(
    AppErrorKind.unknown,
    'Что-то пошло не так',
    hint: 'Попробуйте ещё раз',
  );
}
