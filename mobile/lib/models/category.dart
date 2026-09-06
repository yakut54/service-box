/// Категория товаров (GET /widget/categories).
class Category {
  final String id;
  final String name;
  final String slug;
  final int sortOrder;

  /// 18+: перед показом товаров этой категории спрашиваем возраст, фото
  /// блюрятся до подтверждения (см. AgeGate).
  final bool ageRestricted;

  /// Товары этой категории возврату не подлежат — бейдж на карточке.
  final bool noReturn;

  final List<Category> children;

  const Category({
    required this.id,
    required this.name,
    required this.slug,
    required this.sortOrder,
    this.ageRestricted = false,
    this.noReturn = false,
    this.children = const [],
  });

  factory Category.fromJson(Map<String, dynamic> json) => Category(
    id: json['id'] as String,
    name: json['name'] as String,
    slug: json['slug'] as String,
    sortOrder: (json['sort_order'] as num?)?.toInt() ?? 0,
    ageRestricted: json['age_restricted'] as bool? ?? false,
    noReturn: json['no_return'] as bool? ?? false,
    children:
        (json['children'] as List<dynamic>?)
            ?.map((c) => Category.fromJson(c as Map<String, dynamic>))
            .toList() ??
        const [],
  );
}
