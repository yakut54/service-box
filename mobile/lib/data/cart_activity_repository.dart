import 'api_cart_activity_repository.dart';

/// Активность корзины — только счётчик товаров и момент последнего
/// изменения, состав корзины на сервер не уходит. Питает напоминание о
/// брошенной корзине (см. CartActivitySync, backend: CartActivityController).
/// Доступен только по долгой сессии входа (см. X-Phone-Session, AuthState.session).
abstract class CartActivityRepository {
  Future<void> updateActivity(String sessionToken, int itemsCount);

  factory CartActivityRepository.create() => ApiCartActivityRepository();
}
