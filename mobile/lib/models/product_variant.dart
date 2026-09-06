/// Ось вариативности товара («Размер», «Цвет») с её значениями.
class ProductOption {
  final String name;
  final int position;
  final List<String> values;

  const ProductOption({
    required this.name,
    required this.position,
    required this.values,
  });

  factory ProductOption.fromJson(Map<String, dynamic> json) => ProductOption(
    name: (json['name'] as String?)?.trim() ?? '',
    position: (json['position'] as num?)?.toInt() ?? 1,
    values: ((json['values'] as List<dynamic>?) ?? const [])
        .map((v) => (v as Map<String, dynamic>)['value']?.toString() ?? '')
        .where((v) => v.isNotEmpty)
        .toList(),
  );
}

/// Заведённая комбинация опций — свой остаток, цена, SKU, фото.
/// optionValues — значения по позициям опций (напр. ["M", "Чёрный"]).
class ProductVariant {
  final String id;
  final String? sku;

  /// Цена варианта в копейках; null → цена товара.
  final int? priceKopecks;
  final int stockQuantity;
  final bool allowBackorder;
  final String? imageUrl;
  final List<String> optionValues;
  final bool isActive;

  const ProductVariant({
    required this.id,
    this.sku,
    this.priceKopecks,
    required this.stockQuantity,
    required this.allowBackorder,
    this.imageUrl,
    required this.optionValues,
    required this.isActive,
  });

  factory ProductVariant.fromJson(Map<String, dynamic> json) => ProductVariant(
    id: json['id'] as String,
    sku: json['sku'] as String?,
    priceKopecks: (json['price'] as num?)?.toInt(),
    stockQuantity: (json['stock_quantity'] as num?)?.toInt() ?? 0,
    allowBackorder: json['allow_backorder'] as bool? ?? false,
    imageUrl: json['image_url'] as String?,
    optionValues: ((json['option_values'] as List<dynamic>?) ?? const [])
        .map((v) => v.toString())
        .toList(),
    isActive: json['is_active'] as bool? ?? true,
  );

  bool get inStock => isActive && (stockQuantity > 0 || allowBackorder);

  int effectivePriceKopecks(int productPriceKopecks) =>
      priceKopecks ?? productPriceKopecks;
}
