/// Одна позиция заказа. weightGrams/actualWeightGrams/actualPrice — только
/// для товаров «по весу — перевзвешивание» (sale_mode weight_variable),
/// null для остальных.
class OrderItem {
  final String id;
  final String productName;

  /// «Размер: M · Цвет: Чёрный» — снимок выбранного варианта, null у обычных.
  final String? variantLabel;
  final int quantity;
  final int priceKopecks;
  final int? weightGrams;
  final int? actualWeightGrams;
  final int? actualPriceKopecks;

  const OrderItem({
    required this.id,
    required this.productName,
    this.variantLabel,
    required this.quantity,
    required this.priceKopecks,
    this.weightGrams,
    this.actualWeightGrams,
    this.actualPriceKopecks,
  });

  /// true, пока сборщик ещё не подтвердил вес этой позиции.
  bool get isAwaitingWeighing => weightGrams != null && actualWeightGrams == null;

  double get priceRubles => (actualPriceKopecks ?? priceKopecks) / 100;

  factory OrderItem.fromJson(Map<String, dynamic> json) => OrderItem(
    id: json['id'] as String,
    productName: json['product_name'] as String? ?? '',
    variantLabel: (json['variant_label'] as String?)?.trim(),
    quantity: (json['quantity'] as num?)?.toInt() ?? 1,
    priceKopecks: (json['price'] as num?)?.toInt() ?? 0,
    weightGrams: (json['weight_grams'] as num?)?.toInt(),
    actualWeightGrams: (json['actual_weight_grams'] as num?)?.toInt(),
    actualPriceKopecks: (json['actual_price'] as num?)?.toInt(),
  );
}

/// Заказ, созданный через POST /widget/orders / GET /widget/orders/{id}.
class Order {
  final String id;
  final String status;
  final int totalPriceKopecks;
  final DateTime? createdAt;
  final List<OrderItem> items;

  // Режим «по весу — перевзвешивание» (см. PLAN.md).
  final DateTime? weighedAt;
  final int? surchargeAmountKopecks;
  final String? surchargeStatus; // 'pending' | 'paid' | 'expired'
  final String? surchargePaymentUrl;
  final DateTime? surchargeDeadlineAt;
  final String? paymentUrl;

  const Order({
    required this.id,
    required this.status,
    required this.totalPriceKopecks,
    this.createdAt,
    this.items = const [],
    this.weighedAt,
    this.surchargeAmountKopecks,
    this.surchargeStatus,
    this.surchargePaymentUrl,
    this.surchargeDeadlineAt,
    this.paymentUrl,
  });

  double get totalRubles => totalPriceKopecks / 100;

  /// Доплата ждёт подтверждения покупателя и дедлайн ещё не прошёл —
  /// используется баннером в истории заказов (см. OrdersScreen).
  bool get hasPendingSurcharge {
    if (surchargeStatus != 'pending') return false;
    final deadline = surchargeDeadlineAt;
    return deadline == null || deadline.isAfter(DateTime.now());
  }

  factory Order.fromJson(Map<String, dynamic> json) => Order(
    id: json['id'] as String,
    status: json['status'] as String,
    totalPriceKopecks: (json['total_price'] as num).toInt(),
    createdAt: json['created_at'] != null
        ? DateTime.tryParse(json['created_at'] as String)
        : null,
    items: (json['items'] as List<dynamic>?)
            ?.map((i) => OrderItem.fromJson(i as Map<String, dynamic>))
            .toList() ??
        const [],
    weighedAt: json['weighed_at'] != null
        ? DateTime.tryParse(json['weighed_at'] as String)
        : null,
    surchargeAmountKopecks: (json['surcharge_amount'] as num?)?.toInt(),
    surchargeStatus: json['surcharge_status'] as String?,
    surchargePaymentUrl: json['surcharge_payment_url'] as String?,
    surchargeDeadlineAt: json['surcharge_deadline_at'] != null
        ? DateTime.tryParse(json['surcharge_deadline_at'] as String)
        : null,
    paymentUrl: json['payment_url'] as String?,
  );
}
