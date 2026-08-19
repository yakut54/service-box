import '../models/category.dart';
import '../models/product.dart';
import 'catalog_repository.dart';

/// Заглушка каталога для флейвора demo — работает без бэкенда.
class MockCatalogRepository implements CatalogRepository {
  static const _categories = [
    Category(id: 'cat-fruit', name: 'Фрукты', slug: 'fruit', sortOrder: 1),
    Category(id: 'cat-veg', name: 'Овощи', slug: 'veg', sortOrder: 2),
  ];

  static const _products = [
    Product(
      id: 'p1',
      name: 'Яблоки Голден',
      description: 'Сладкие и сочные яблоки с тонким медовым ароматом.',
      priceKopecks: 12000,
      categoryId: 'cat-fruit',
      physical: ProductPhysical(stockQuantity: 50, allowBackorder: false),
    ),
    Product(
      id: 'p2',
      name: 'Бананы',
      description: 'Спелые жёлтые бананы, богаты калием.',
      priceKopecks: 9500,
      categoryId: 'cat-fruit',
      physical: ProductPhysical(stockQuantity: 30, allowBackorder: false),
    ),
    Product(
      id: 'p3',
      name: 'Морковь свежая',
      description: 'Морковь насыщенного оранжевого цвета, сладкая и сочная.',
      priceKopecks: 5500,
      categoryId: 'cat-veg',
      physical: ProductPhysical(stockQuantity: 0, allowBackorder: false),
    ),
  ];

  @override
  Future<List<Category>> fetchCategories() async {
    await Future.delayed(const Duration(milliseconds: 300));
    return _categories;
  }

  @override
  Future<List<Product>> fetchProducts({String? categoryId, String? search}) async {
    await Future.delayed(const Duration(milliseconds: 300));
    return _products.where((p) {
      if (categoryId != null && p.categoryId != categoryId) return false;
      if (search != null &&
          search.isNotEmpty &&
          !p.name.toLowerCase().contains(search.toLowerCase())) {
        return false;
      }
      return true;
    }).toList();
  }
}
