/// Заказ, созданный через POST /widget/orders / GET /widget/orders/{id}.
class Order {
  final String id;
  final String status;
  final int totalPriceKopecks;

  const Order({
    required this.id,
    required this.status,
    required this.totalPriceKopecks,
  });

  double get totalRubles => totalPriceKopecks / 100;

  factory Order.fromJson(Map<String, dynamic> json) => Order(
    id: json['id'] as String,
    status: json['status'] as String,
    totalPriceKopecks: (json['total_price'] as num).toInt(),
  );
}
