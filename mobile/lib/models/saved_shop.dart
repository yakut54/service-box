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

/// Один магазин, сохранённый в телефоне байера.
class SavedShop {
  /// Короткий код из QR/ссылки, например 'FRUIT7'.
  final String appCode;

  /// shops.api_key на бэкенде — именно это значение уходит в заголовок
  /// X-Shop-ID при любом обращении к widget API. Это НЕ то же самое,
  /// что shops.id — см. PLAN.md и app/Http/Middleware/TenantContext.php.
  final String shopId;

  final String name;
  final ShopTheme theme;
  final String? timezone;

  /// Когда магазин впервые добавлен — для сортировки списка.
  final DateTime addedAt;

  /// Когда магазин последний раз открывали — активный магазин показываем сверху.
  final DateTime? lastOpenedAt;

  const SavedShop({
    required this.appCode,
    required this.shopId,
    required this.name,
    required this.theme,
    required this.addedAt,
    this.timezone,
    this.lastOpenedAt,
  });

  /// Разбор ответа GET /app/{code} (сейчас — мокового, потом — настоящего).
  factory SavedShop.fromApi(String appCode, Map<String, dynamic> json) {
    // Важно: X-Shop-ID это shops.api_key, а не shops.id — эти поля разные.
    final id = (json['api_key'] ?? json['shop_id']) as String?;
    if (id == null || id.isEmpty) {
      throw StateError('Ответ сервера не содержит api_key магазина');
    }
    final name = (json['name'] as String?)?.trim();
    return SavedShop(
      appCode: appCode,
      shopId: id,
      name: (name == null || name.isEmpty) ? 'Магазин' : name,
      theme: ShopTheme.fromJson(json['widget_config'] as Map<String, dynamic>?),
      timezone: json['timezone'] as String?,
      addedAt: DateTime.now(),
    );
  }

  factory SavedShop.fromJson(Map<String, dynamic> json) => SavedShop(
        appCode: json['app_code'] as String,
        shopId: json['shop_id'] as String,
        name: json['name'] as String,
        theme: ShopTheme.fromJson(json['theme'] as Map<String, dynamic>?),
        timezone: json['timezone'] as String?,
        addedAt: DateTime.tryParse(json['added_at'] as String? ?? '') ?? DateTime.now(),
        lastOpenedAt: json['last_opened_at'] != null
            ? DateTime.tryParse(json['last_opened_at'] as String)
            : null,
      );

  Map<String, dynamic> toJson() => {
        'app_code': appCode,
        'shop_id': shopId,
        'name': name,
        'theme': theme.toJson(),
        'timezone': timezone,
        'added_at': addedAt.toIso8601String(),
        'last_opened_at': lastOpenedAt?.toIso8601String(),
      };

  SavedShop copyWith({DateTime? addedAt, DateTime? lastOpenedAt}) => SavedShop(
        appCode: appCode,
        shopId: shopId,
        name: name,
        theme: theme,
        timezone: timezone,
        addedAt: addedAt ?? this.addedAt,
        lastOpenedAt: lastOpenedAt ?? this.lastOpenedAt,
      );
}
