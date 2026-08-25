import '../models/review.dart';
import 'api_review_repository.dart';

/// Отзывы на товар. X-Phone-Session (та же 60-дневная сессия, что у
/// заказов/адресов/профиля) — как определяется "свой" отзыв на сервере:
/// без него сервер не может сказать, отправлял ли уже этот покупатель
/// отзыв на товар, см. ReviewController::resolveCustomer.
abstract class ReviewRepository {
  /// sessionToken — необязателен (гость видит список без my_review), но
  /// если покупатель залогинен — всегда передавать, иначе ProductReviews.
  /// myReview всегда будет null и форма отправки будет доступна повторно.
  Future<ProductReviews> fetch(String productId, {String? sessionToken});

  /// Бросает AppException.badResponse('Вы уже оставили отзыв на этот
  /// товар') при повторной отправке на тот же товар — серверная проверка
  /// по customer_id из sessionToken (customerPhone — запасной путь для
  /// анонимного веб-виджета, для мобилки не определяющий).
  /// Возвращает void: сервер отвечает уже созданным, но неопубликованным
  /// отзывом — показывать его автору нельзя напрямую, после отправки
  /// нужно перезапросить fetch() — my_review в нём это подхватит.
  Future<void> submit({
    required String productId,
    required int rating,
    String? text,
    required String customerName,
    required String customerPhone,
    required String sessionToken,
  });

  factory ReviewRepository.create() => ApiReviewRepository();
}
