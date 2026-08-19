import '../core/app_exception.dart';
import '../models/category.dart';
import '../models/product.dart';
import 'api_client.dart';
import 'catalog_repository.dart';

class ApiCatalogRepository implements CatalogRepository {
  final ApiClient _client = ApiClient();

  @override
  Future<List<Category>> fetchCategories() async {
    final json = await _client.get('/widget/categories');
    final list = json['data'] as List<dynamic>? ?? const [];
    return list
        .map((c) => Category.fromJson(c as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<Product>> fetchProducts({
    String? categoryId,
    String? search,
  }) async {
    final json = await _client.get(
      '/widget/products',
      query: {
        'active': 'true',
        // MVP мобильного приложения — только физические товары.
        'type': 'physical',
        if (categoryId != null) 'category_id': categoryId,
        if (search != null && search.isNotEmpty) 'search': search,
      },
    );
    final list = json['data'] as List<dynamic>? ?? const [];
    return list
        .map((p) => Product.fromJson(p as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<Product> fetchProduct(String id) async {
    final json = await _client.get('/widget/products/$id');
    final data = json['data'] as Map<String, dynamic>?;
    if (data == null) throw AppException.notFound();
    return Product.fromJson(data);
  }
}
