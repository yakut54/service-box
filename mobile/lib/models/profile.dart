/// Профиль байера (см. GET/PUT /widget/profile).
class Profile {
  final String name;
  final String phone;
  final String? email;
  final String? avatarUrl;
  final int totalOrders;
  final int totalSpentKopecks;
  final int bonusBalance;

  const Profile({
    required this.name,
    required this.phone,
    this.email,
    this.avatarUrl,
    required this.totalOrders,
    required this.totalSpentKopecks,
    required this.bonusBalance,
  });

  double get totalSpentRubles => totalSpentKopecks / 100;

  factory Profile.fromJson(Map<String, dynamic> json) => Profile(
    name: json['name'] as String? ?? '',
    phone: json['phone'] as String,
    email: json['email'] as String?,
    avatarUrl: json['avatar_url'] as String?,
    totalOrders: (json['total_orders'] as num?)?.toInt() ?? 0,
    totalSpentKopecks: (json['total_spent'] as num?)?.toInt() ?? 0,
    bonusBalance: (json['bonus_balance'] as num?)?.toInt() ?? 0,
  );
}

/// Настраиваемые категории push (см. GET/PUT /widget/profile/notification-prefs).
/// Транзакционные (статус заказа, доплата, чат) не отключаются и сюда не входят.
class NotificationPrefs {
  /// Брошенная корзина, снова в наличии, возвращение — по умолчанию включено.
  final bool behavioral;

  /// Промо и рассылки магазина — по умолчанию выключено.
  final bool campaign;

  const NotificationPrefs({required this.behavioral, required this.campaign});

  NotificationPrefs copyWith({bool? behavioral, bool? campaign}) =>
      NotificationPrefs(
        behavioral: behavioral ?? this.behavioral,
        campaign: campaign ?? this.campaign,
      );

  Map<String, dynamic> toJson() => {
    'behavioral': behavioral,
    'campaign': campaign,
  };

  factory NotificationPrefs.fromJson(Map<String, dynamic> json) =>
      NotificationPrefs(
        behavioral: json['behavioral'] as bool? ?? true,
        campaign: json['campaign'] as bool? ?? false,
      );
}
