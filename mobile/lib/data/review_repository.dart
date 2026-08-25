import '../models/review.dart';
import 'api_review_repository.dart';

/// Отзывы на товар. Оба эндпоинта — под обычной widget-авторизацией
/// (X-Shop-ID), без сессионного заголовка: телефон при отправке едет
/// просто полем в теле запроса, см. ReviewController::widgetStore.
abstract class ReviewRepository {
  Future<ProductReviews> fetch(String productId);

  /// Бросает AppException.badResponse('Вы уже оставили отзыв на этот
  /// товар') при повторной отправке с тем же customerPhone на тот же
  /// товар — это серверная проверка (уникальность product_id+customer_id),
  /// включается только когда customerPhone передан и находится в БД.
  /// Возвращает void: сервер отвечает уже созданным, но неопубликованным
  /// отзывом — показывать его автору нельзя (см. PLAN.md).
  Future<void> submit({
    required String productId,
    required int rating,
    String? text,
    required String customerName,
    required String customerPhone,
  });

  factory ReviewRepository.create() => ApiReviewRepository();
}
