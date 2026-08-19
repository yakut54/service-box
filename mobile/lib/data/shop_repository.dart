import '../models/saved_shop.dart';
import '../core/flavor_config.dart';
import 'api_shop_repository.dart';
import 'mock_shop_repository.dart';

/// Источник данных о магазине этой сборки.
abstract class ShopRepository {
  /// Загрузить магазин этой сборки. Бросает AppException при ошибке.
  Future<SavedShop> fetchByCode(String appCode);

  factory ShopRepository.create() {
    return FlavorConfig.useMocks ? MockShopRepository() : ApiShopRepository();
  }
}
