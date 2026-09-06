import 'size_chart.dart';

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
  /// weightVariable — по весу, вес плавает (перевзвешивание при сборке —
  /// см. PLAN.md, «По весу — перевзвешивание»; заявленный при заказе вес
  /// приблизительный, точную сумму сборщик подтверждает на весах).
  final ProductSaleMode saleMode;
  final int weightStepGrams;
  final int weightMinGrams;
  final int weightMaxGrams;

  /// Многоштучная упаковка: сколько единиц в товаре и как их называть
  /// («6», «шт»). null → товар не упаковка, цену за единицу не показываем.
  final int? unitsPerPack;
  final String? unitLabel;

  /// Код маркировки «Честный знак» — показываем в характеристиках как есть.
  final String? markingCode;

  /// Остаток на складе в граммах — для weightFixed списывается сразу при
  /// заказе; для weightVariable списывается позже, при фактическом
  /// взвешивании (см. OrderReweighService на бэкенде), поэтому на клиенте
  /// это поле для weightVariable не влияет на доступность к заказу.
  final int stockWeightGrams;

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
    this.stockWeightGrams = 0,
    this.unitsPerPack,
    this.unitLabel,
    this.markingCode,
  });

  /// В наличии ли товар — с учётом того, что магазин разрешает
  /// принимать заказы даже при нулевом остатке (allow_backorder).
  /// weightVariable всегда «в наличии» на этапе заказа — точное количество
  /// известно только при взвешивании, остаток на складе enforce'ится там
  /// (см. OrderReweighService), не в момент заказа.
  bool get inStock => switch (saleMode) {
    ProductSaleMode.weightVariable => true,
    ProductSaleMode.weightFixed => stockWeightGrams > 0 || allowBackorder,
    ProductSaleMode.piece => stockQuantity > 0 || allowBackorder,
  };

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
        stockWeightGrams: (json['stock_weight_grams'] as num?)?.toInt() ?? 0,
        unitsPerPack: (json['units_per_pack'] as num?)?.toInt(),
        unitLabel: (json['unit_label'] as String?)?.trim(),
        markingCode: (json['marking_code'] as String?)?.trim(),
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

/// Произвольная характеристика товара — пара «label: value», которую шопер
/// завёл в админке (см. ProductAttribute на бэкенде). Показывается в блоке
/// «Характеристики» на карточке товара вместе со структурными полями.
class ProductAttribute {
  final String label;
  final String value;

  const ProductAttribute({required this.label, required this.value});

  factory ProductAttribute.fromJson(Map<String, dynamic> json) => ProductAttribute(
    label: (json['label'] as String?)?.trim() ?? '',
    value: (json['value'] as String?)?.trim() ?? '',
  );

  bool get isEmpty => label.isEmpty || value.isEmpty;
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

  /// Флаги категории товара, продублированные на товар для карточки:
  /// 18+ гейт и «возврату не подлежит» (см. Category). Берутся из вложенного
  /// объекта category в ответе /widget/products(/{id}).
  final bool categoryAgeRestricted;
  final bool categoryNoReturn;

  /// Размерная сетка (одежда/обувь) — кнопка «Таблица размеров» на карточке.
  final SizeChart? sizeChart;

  final ProductPhysical? physical;

  /// Средний рейтинг и количество отзывов — считаются на бэкенде по
  /// опубликованным отзывам (см. ProductController::index/show,
  /// withAvg/withCount). null/0, если отзывов ещё нет.
  final double? rating;
  final int reviewCount;

  /// Доп. фото галереи (не включает обложку — см. imageUrl). Пусто для
  /// товаров без галереи (М1) — карточка тогда показывает одну обложку,
  /// как раньше.
  final List<String> images;

  /// Произвольные характеристики «label: value» из админки. Пусто, если шопер
  /// ничего не завёл — блок «Характеристики» тогда покажет только структурные
  /// поля (вес/размер/цвет), как раньше.
  final List<ProductAttribute> attributes;

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
    this.categoryAgeRestricted = false,
    this.categoryNoReturn = false,
    this.sizeChart,
    this.physical,
    this.rating,
    this.reviewCount = 0,
    this.images = const [],
    this.attributes = const [],
  });

  double get priceRubles => priceKopecks / 100;

  bool get inStock => physical?.inStock ?? true;

  bool get isWeightBased => physical?.isWeightBased ?? false;

  /// Цена за конкретный вес (граммы) — округление до копейки, идентично
  /// серверному расчёту в OrderController::store, чтобы то, что видит
  /// покупатель на ползунке, совпадало с суммой списания.
  int priceForWeightGrams(int grams) => (priceKopecks * grams / 1000).round();

  bool get hasDiscount => discountPercent != null;

  /// Цена за единицу для многоштучной упаковки — «208 ₽/шт» рядом с ценой
  /// (Baymard принцип 3: сравнимость выгодности покупки). null, если товар
  /// не упаковка (меньше 2 единиц) или продаётся по весу.
  int? get unitPriceKopecks {
    final n = physical?.unitsPerPack;
    if (isWeightBased || n == null || n < 2) return null;
    return (displayPriceKopecks / n).round();
  }

  /// Как называть единицу упаковки («шт», «пара», «саше») — «шт» по умолчанию.
  String get unitLabel {
    final l = physical?.unitLabel;
    return (l != null && l.isNotEmpty) ? l : 'шт';
  }

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
    categoryAgeRestricted:
        (json['category'] as Map<String, dynamic>?)?['age_restricted'] as bool? ?? false,
    categoryNoReturn:
        (json['category'] as Map<String, dynamic>?)?['no_return'] as bool? ?? false,
    sizeChart: json['size_chart'] != null
        ? SizeChart.fromJson(json['size_chart'] as Map<String, dynamic>)
        : null,
    physical: json['physical'] != null
        ? ProductPhysical.fromJson(json['physical'] as Map<String, dynamic>)
        : null,
    // Бэкенд отдаёт (float) после round(avg,1), но на всякий случай
    // принимаем и строку — Postgres avg() иногда сериализуется как текст.
    rating: switch (json['rating']) {
      final num n => n.toDouble(),
      final String s => double.tryParse(s),
      _ => null,
    },
    reviewCount: (json['review_count'] as num?)?.toInt() ?? 0,
    images: (json['images'] as List<dynamic>? ?? const [])
        .map((img) => (img as Map<String, dynamic>)['url'] as String)
        .toList(),
    attributes: (json['product_attributes'] as List<dynamic>? ?? const [])
        .map((a) => ProductAttribute.fromJson(a as Map<String, dynamic>))
        .where((a) => !a.isEmpty)
        .toList(),
  );
}
