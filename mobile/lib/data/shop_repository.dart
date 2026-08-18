import '../models/saved_shop.dart';
import '../core/config.dart';
import 'mock_shop_repository.dart';

/// Источник данных о магазине по его короткому коду.
/// Сейчас есть только моковая реализация — бэкенд ещё не поднят.
/// Когда появится настоящий GET /app/{code}, нужно будет написать
/// ApiShopRepository с таким же методом fetchByCode и подставить его
/// в фабрику create() — остальной код (state, экраны) трогать не придётся.
abstract class ShopRepository {
  /// Найти магазин по короткому коду. Бросает AppException при ошибке.
  Future<SavedShop> fetchByCode(String appCode);

  factory ShopRepository.create() {
    if (AppConfig.useMocks) return MockShopRepository();
    throw UnimplementedError(
      'Реальный ApiShopRepository ещё не написан — бэкенд пока не развёрнут',
    );
  }
}
