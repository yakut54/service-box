import '../core/flavor_config.dart';
import '../models/category.dart';
import '../models/product.dart';
import 'api_catalog_repository.dart';
import 'mock_catalog_repository.dart';

/// Источник данных о категориях и товарах магазина этой сборки.
abstract class CatalogRepository {
  Future<List<Category>> fetchCategories();

  /// MVP показывает только физические товары (см. PLAN.md, «Флот Шоперов»).
  Future<List<Product>> fetchProducts({String? categoryId, String? search});

  factory CatalogRepository.create() {
    return FlavorConfig.useMocks ? MockCatalogRepository() : ApiCatalogRepository();
  }
}
