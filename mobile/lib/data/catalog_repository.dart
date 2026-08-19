import '../models/category.dart';
import '../models/product.dart';
import 'api_catalog_repository.dart';

/// Источник данных о категориях и товарах магазина этой сборки.
abstract class CatalogRepository {
  Future<List<Category>> fetchCategories();

  /// MVP показывает только физические товары (см. PLAN.md, «Флот Шоперов»).
  Future<List<Product>> fetchProducts({String? categoryId, String? search});

  /// Карточка одного товара. Бросает AppException.notFound(), если товар
  /// удалён/деактивирован между открытием каталога и переходом в карточку.
  Future<Product> fetchProduct(String id);

  factory CatalogRepository.create() => ApiCatalogRepository();
}
