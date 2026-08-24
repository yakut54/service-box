/// Складские данные физического товара (GET /widget/products, /widget/products/{id}).
class ProductPhysical {
  final String? sku;
  final int stockQuantity;
  final bool allowBackorder;
  final String? color;
  final int? weightGrams;
  final String? dimensions;

  /// Режим продажи (см. PLAN.md, «Развесной товар — финальное ТЗ»):
  /// piece — целыми штуками (по умолчанию, всё как раньше);
  /// weightFixed — по весу, продавец фасует ровно под заказ;
  /// weightVariable — по весу, вес плавает (перевзвешивание при сборке,
  /// пока не реализовано на бэкенде дальше базового ценообразования).
  final ProductSaleMode saleMode;
  final int weightStepGrams;
  final int weightMinGrams;
  final int weightMaxGrams;

  const ProductPhysical({
    this.sku,
    required this.stockQuantity,
    required this.allowBackorder,
    this.color,
    this.weightGrams,
    this.dimensions,
    this.saleMode = ProductSaleMode.piece,
    this.weightStepGrams = 100,
    this.weightMinGrams = 100,
    this.weightMaxGrams = 5000,
  });

  /// В наличии ли товар — с учётом того, что магазин разрешает
  /// принимать заказы даже при нулевом остатке (allow_backorder).
  /// Остаток по весу не отслеживается — весовой товар всегда «в наличии».
  bool get inStock => saleMode != ProductSaleMode.piece || stockQuantity > 0 || allowBackorder;

  bool get isWeightBased => saleMode != ProductSaleMode.piece;

  factory ProductPhysical.fromJson(Map<String, dynamic> json) =>
      ProductPhysical(
        sku: json['sku'] as String?,
        stockQuantity: (json['stock_quantity'] as num?)?.toInt() ?? 0,
        allowBackorder: json['allow_backorder'] as bool? ?? false,
        color: json['color'] as String?,
        weightGrams: (json['weight_grams'] as num?)?.toInt(),
        dimensions: json['dimensions'] as String?,
        saleMode: ProductSaleMode.fromJson(json['sale_mode'] as String?),
        weightStepGrams: (json['weight_step_grams'] as num?)?.toInt() ?? 100,
        weightMinGrams: (json['weight_min_grams'] as num?)?.toInt() ?? 100,
        weightMaxGrams: (json['weight_max_grams'] as num?)?.toInt() ?? 5000,
      );
}

enum ProductSaleMode {
  piece,
  weightFixed,
  weightVariable;

  static ProductSaleMode fromJson(String? value) => switch (value) {
    'weight_fixed' => ProductSaleMode.weightFixed,
    'weight_variable' => ProductSaleMode.weightVariable,
    _ => ProductSaleMode.piece,
  };
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

  /// Старая цена «для показа скидки» (админка, ручное поле) — запасной
  /// источник бейджа, если для товара нет активной скидки из раздела
  /// «Скидки» (см. discountPercentFromDiscounts).
  final int? comparePriceKopecks;

  /// % лучшей активной auto-apply скидки (см. Discount, DiscountService::
  /// bestBadge на бэкенде) — null, если на товар сейчас нет скидки.
  /// Это ОСНОВНОЙ источник бейджа: реальные скидки настраиваются в разделе
  /// «Скидки» админки, а не через compare_price, который почти никогда не
  /// заполнен.
  final int? discountPercentFromDiscounts;

  /// Цена ПОСЛЕ discountPercentFromDiscounts, в копейках — считается на
  /// бэкенде из той же суммы скидки, что и процент (DiscountService::
  /// bestBadge), а не пересчитывается на клиенте обратным счётом от
  /// округлённого процента (риск разъехаться с реальной суммой списания —
  /// тот же класс бага, что в М10/М11). Есть только когда есть
  /// discountPercentFromDiscounts.
  final int? discountPriceKopecks;

  /// Когда discountPercentFromDiscounts перестанет действовать — null,
  /// если скидка бессрочная (см. Discount.ends_at на бэкенде).
  final DateTime? discountEndsAt;

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
    this.comparePriceKopecks,
    this.discountPercentFromDiscounts,
    this.discountPriceKopecks,
    this.discountEndsAt,
    this.imageUrl,
    this.categoryId,
    this.physical,
    this.images = const [],
  });

  double get priceRubles => priceKopecks / 100;

  bool get inStock => physical?.inStock ?? true;

  bool get isWeightBased => physical?.isWeightBased ?? false;

  /// Цена за конкретный вес (граммы) — округление до копейки, идентично
  /// серверному расчёту в OrderController::store, чтобы то, что видит
  /// покупатель на ползунке, совпадало с суммой списания.
  int priceForWeightGrams(int grams) => (priceKopecks * grams / 1000).round();

  bool get hasDiscount => discountPercent != null;

  /// Процент скидки для бейджа на карточке — null, если скидки нет.
  /// Приоритет: реальная скидка из раздела «Скидки» (discountPercentFromDiscounts),
  /// иначе ручное поле compare_price, если оно больше текущей цены.
  int? get discountPercent {
    if (discountPercentFromDiscounts != null) return discountPercentFromDiscounts;
    if (comparePriceKopecks != null && comparePriceKopecks! > priceKopecks) {
      return (100 - (priceKopecks / comparePriceKopecks! * 100)).round();
    }
    return null;
  }

  /// Цена жирным — при скидке из «Скидки» это discountPriceKopecks с
  /// бэкенда, иначе обычная priceKopecks (compare_price саму price не
  /// трогает, она только источник зачёркнутой цены).
  int get displayPriceKopecks =>
      discountPercentFromDiscounts != null && discountPriceKopecks != null
      ? discountPriceKopecks!
      : priceKopecks;

  /// Зачёркнутая цена рядом с displayPriceKopecks — null, если показывать
  /// нечего. Тот же приоритет, что и в discountPercent: реальная скидка
  /// сначала, ручной compare_price — запасной вариант.
  int? get oldPriceKopecks {
    if (discountPercentFromDiscounts != null) return priceKopecks;
    if (comparePriceKopecks != null && comparePriceKopecks! > priceKopecks) {
      return comparePriceKopecks;
    }
    return null;
  }

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
    comparePriceKopecks: (json['compare_price'] as num?)?.toInt(),
    discountPercentFromDiscounts: (json['discount_percent'] as num?)?.toInt(),
    discountPriceKopecks: (json['discount_price'] as num?)?.toInt(),
    discountEndsAt: json['discount_ends_at'] != null
        ? DateTime.tryParse(json['discount_ends_at'] as String)
        : null,
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
