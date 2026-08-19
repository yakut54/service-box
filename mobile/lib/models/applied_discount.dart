/// Применённая скидка — с промокодом (code != null) или автоматическая
/// (code == null, шопер настроил порог в админке). Сумма — в копейках,
/// как и цены товаров (см. Product.priceKopecks).
class AppliedDiscount {
  final String? code;
  final String name;
  final int amountKopecks;

  const AppliedDiscount({
    this.code,
    required this.name,
    required this.amountKopecks,
  });

  double get amountRubles => amountKopecks / 100;
}
