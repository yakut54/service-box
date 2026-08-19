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

  const SavedShop({
    required this.appCode,
    required this.shopId,
    required this.name,
    required this.theme,
    this.timezone,
  });

  /// Разбор ответа MockShopRepository — там api_key присутствует в теле
  /// ответа, потому что мок повторяет старый (уже отменённый) контракт
  /// "поиска магазина по коду". Настоящий бэкенд так не отвечает — см. fromWidgetShop.
  factory SavedShop.fromApi(String appCode, Map<String, dynamic> json) {
    final id = (json['api_key'] ?? json['shop_id']) as String?;
    if (id == null || id.isEmpty) {
      throw StateError('Мок-ответ не содержит api_key магазина');
    }
    final name = (json['name'] as String?)?.trim();
    return SavedShop(
      appCode: appCode,
      shopId: id,
      name: (name == null || name.isEmpty) ? 'Магазин' : name,
      theme: ShopTheme.fromJson(json['widget_config'] as Map<String, dynamic>?),
      timezone: json['timezone'] as String?,
    );
  }

  /// Разбор настоящего ответа GET /widget/shop. В отличие от мока, здесь
  /// нет api_key в теле ответа — X-Shop-ID уже известен из FlavorConfig
  /// (им же авторизован сам этот запрос), поэтому shopId передаётся явно,
  /// а не парсится из JSON.
  factory SavedShop.fromWidgetShop(
    String appCode,
    String shopId,
    Map<String, dynamic> json,
  ) {
    final name = (json['name'] as String?)?.trim();
    return SavedShop(
      appCode: appCode,
      shopId: shopId,
      name: (name == null || name.isEmpty) ? 'Магазин' : name,
      theme: ShopTheme.fromJson(json['widget_config'] as Map<String, dynamic>?),
      timezone: json['timezone'] as String?,
    );
  }

  factory SavedShop.fromJson(Map<String, dynamic> json) => SavedShop(
    appCode: json['app_code'] as String,
    shopId: json['shop_id'] as String,
    name: json['name'] as String,
    theme: ShopTheme.fromJson(json['theme'] as Map<String, dynamic>?),
    timezone: json['timezone'] as String?,
  );

  Map<String, dynamic> toJson() => {
    'app_code': appCode,
    'shop_id': shopId,
    'name': name,
    'theme': theme.toJson(),
    'timezone': timezone,
  };
}
