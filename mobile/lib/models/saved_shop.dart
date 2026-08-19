/// Оформление магазина — приходит с сервера в widget_config (см. GET /app/{code}).
/// Цвета храним строками ровно как в API ('#7C3AED'), чтобы не терять точность
/// при сохранении в телефон и обратно.
class ShopTheme {
  final String? primaryColor;
  final String? bgColor;
  final String? textColor;
  final String? logoUrl;
  final String? preset; // light | dark | minimal
  final int borderRadius;

  const ShopTheme({
    this.primaryColor,
    this.bgColor,
    this.textColor,
    this.logoUrl,
    this.preset,
    this.borderRadius = 8,
  });

  factory ShopTheme.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const ShopTheme();
    return ShopTheme(
      primaryColor: json['primary_color'] as String?,
      bgColor: json['bg_color'] as String?,
      textColor: json['text_color'] as String?,
      logoUrl: json['logo_url'] as String?,
      preset: json['preset'] as String?,
      borderRadius: (json['border_radius'] as num?)?.toInt() ?? 8,
    );
  }

  Map<String, dynamic> toJson() => {
    'primary_color': primaryColor,
    'bg_color': bgColor,
    'text_color': textColor,
    'logo_url': logoUrl,
    'preset': preset,
    'border_radius': borderRadius,
  };
}

/// Магазин, зашитый в эту сборку приложения (один APK = один шопер).
class SavedShop {
  /// Код магазина, зашитый на этапе сборки (см. FlavorConfig.shopCode).
  final String appCode;

  /// shops.api_key на бэкенде — именно это значение уходит в заголовок
  /// X-Shop-ID при любом обращении к widget API. Это НЕ то же самое,
  /// что shops.id — см. PLAN.md и app/Http/Middleware/TenantContext.php.
  final String shopId;

  final String name;
  final ShopTheme theme;
  final String? timezone;

  /// Адрес самовывоза — только если шопер включил этот способ доставки
  /// и заполнил адрес в настройках (delivery_settings.pickup). null —
  /// самовывоз либо выключен, либо адрес не указан; в этом случае
  /// на экране оформления просто не показываем строку с адресом.
  final String? pickupAddress;

  const SavedShop({
    required this.appCode,
    required this.shopId,
    required this.name,
    required this.theme,
    this.timezone,
    this.pickupAddress,
  });

  /// Разбор настоящего ответа GET /widget/shop. Здесь нет api_key в теле
  /// ответа — X-Shop-ID уже известен из FlavorConfig (им же авторизован
  /// сам этот запрос), поэтому shopId передаётся явно, а не парсится из JSON.
  factory SavedShop.fromWidgetShop(
    String appCode,
    String shopId,
    Map<String, dynamic> json,
  ) {
    final name = (json['name'] as String?)?.trim();
    final deliverySettings = json['delivery_settings'] as Map<String, dynamic>?;
    final pickup = deliverySettings?['pickup'] as Map<String, dynamic>?;
    final pickupAddress = (pickup?['address'] as String?)?.trim();

    return SavedShop(
      appCode: appCode,
      shopId: shopId,
      name: (name == null || name.isEmpty) ? 'Магазин' : name,
      theme: ShopTheme.fromJson(json['widget_config'] as Map<String, dynamic>?),
      timezone: json['timezone'] as String?,
      pickupAddress: (pickupAddress != null && pickupAddress.isNotEmpty)
          ? pickupAddress
          : null,
    );
  }

  factory SavedShop.fromJson(Map<String, dynamic> json) => SavedShop(
    appCode: json['app_code'] as String,
    shopId: json['shop_id'] as String,
    name: json['name'] as String,
    theme: ShopTheme.fromJson(json['theme'] as Map<String, dynamic>?),
    timezone: json['timezone'] as String?,
    pickupAddress: json['pickup_address'] as String?,
  );

  Map<String, dynamic> toJson() => {
    'app_code': appCode,
    'shop_id': shopId,
    'name': name,
    'theme': theme.toJson(),
    'timezone': timezone,
    'pickup_address': pickupAddress,
  };
}
