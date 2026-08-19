/// Категория товаров (GET /widget/categories).
class Category {
  final String id;
  final String name;
  final String slug;
  final int sortOrder;
  final List<Category> children;

  const Category({
    required this.id,
    required this.name,
    required this.slug,
    required this.sortOrder,
    this.children = const [],
  });

  factory Category.fromJson(Map<String, dynamic> json) => Category(
        id: json['id'] as String,
        name: json['name'] as String,
        slug: json['slug'] as String,
        sortOrder: (json['sort_order'] as num?)?.toInt() ?? 0,
        children: (json['children'] as List<dynamic>?)
                ?.map((c) => Category.fromJson(c as Map<String, dynamic>))
                .toList() ??
            const [],
      );
}
