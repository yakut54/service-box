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
