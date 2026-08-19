/// Сохранённый адрес доставки (см. /widget/addresses на бэкенде).
class Address {
  final String id;
  final String? label;
  final String city;
  final String street;
  final String building;
  final String? apartment;
  final String? postalCode;
  final bool isDefault;

  const Address({
    required this.id,
    this.label,
    required this.city,
    required this.street,
    required this.building,
    this.apartment,
    this.postalCode,
    required this.isDefault,
  });

  String get shortLine {
    final apt = apartment;
    return (apt != null && apt.isNotEmpty)
        ? '$street, $building, кв. $apt'
        : '$street, $building';
  }

  String get fullLine => '$city, $shortLine';

  factory Address.fromJson(Map<String, dynamic> json) => Address(
    id: json['id'] as String,
    label: json['label'] as String?,
    city: json['city'] as String,
    street: json['street'] as String,
    building: json['building'] as String,
    apartment: json['apartment'] as String?,
    postalCode: json['postal_code'] as String?,
    isDefault: json['is_default'] as bool? ?? false,
  );
}
