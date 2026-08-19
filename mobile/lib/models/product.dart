/// Складские данные физического товара (GET /widget/products, /widget/products/{id}).
class ProductPhysical {
  final String? sku;
  final int stockQuantity;
  final bool allowBackorder;
  final String? color;
  final int? weightGrams;
  final String? dimensions;

  const ProductPhysical({
    this.sku,
    required this.stockQuantity,
    required this.allowBackorder,
    this.color,
    this.weightGrams,
    this.dimensions,
  });

  /// В наличии ли товар — с учётом того, что магазин разрешает
  /// принимать заказы даже при нулевом остатке (allow_backorder).
  bool get inStock => stockQuantity > 0 || allowBackorder;

  factory ProductPhysical.fromJson(Map<String, dynamic> json) =>
      ProductPhysical(
        sku: json['sku'] as String?,
        stockQuantity: (json['stock_quantity'] as num?)?.toInt() ?? 0,
        allowBackorder: json['allow_backorder'] as bool? ?? false,
        color: json['color'] as String?,
        weightGrams: (json['weight_grams'] as num?)?.toInt(),
        dimensions: json['dimensions'] as String?,
      );
}

/// Физический товар. MVP мобильного приложения показывает только этот тип
/// (см. PLAN.md, «Флот Шоперов») — услуги и цифровые товары не запрашиваются.
class Product {
  final String id;
  final String name;
  final String? description;

  /// Цена в копейках — как хранится на бэкенде. Для показа использовать
  /// core/format.dart → formatRubles(priceRubles).
  final int priceKopecks;

  final String? imageUrl;
  final String? categoryId;
  final ProductPhysical? physical;

  /// Доп. фото галереи (не включает обложку — см. imageUrl). Пусто для
  /// товаров без галереи (М1) — карточка тогда показывает одну обложку,
  /// как раньше.
  final List<String> images;

  const Product({
    required this.id,
    required this.name,
    this.description,
    required this.priceKopecks,
    this.imageUrl,
    this.categoryId,
    this.physical,
    this.images = const [],
  });

  double get priceRubles => priceKopecks / 100;

  bool get inStock => physical?.inStock ?? true;

  /// Обложка первой, затем доп. фото — то, что реально листает галерея
  /// на карточке товара.
  List<String> get galleryImages => [
    if (imageUrl != null && imageUrl!.isNotEmpty) imageUrl!,
    ...images,
  ];

  factory Product.fromJson(Map<String, dynamic> json) => Product(
    id: json['id'] as String,
    name: json['name'] as String,
    description: json['description'] as String?,
    priceKopecks: (json['price'] as num).toInt(),
    imageUrl: json['image_url'] as String?,
    categoryId: json['category_id'] as String?,
    physical: json['physical'] != null
        ? ProductPhysical.fromJson(json['physical'] as Map<String, dynamic>)
        : null,
    images: (json['images'] as List<dynamic>? ?? const [])
        .map((img) => (img as Map<String, dynamic>)['url'] as String)
        .toList(),
  );
}
