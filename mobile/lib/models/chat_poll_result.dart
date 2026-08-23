/// Компактный результат опроса чата (см. GET /widget/chat/poll) — вместо
/// полного списка сообщений, чтобы не гонять трафик на каждый тик таймера.
class ChatPollResult {
  final bool hasNew;
  final int unreadTotal;
  final DateTime? shopReadUpTo;

  const ChatPollResult({
    required this.hasNew,
    required this.unreadTotal,
    this.shopReadUpTo,
  });

  factory ChatPollResult.fromJson(Map<String, dynamic> json) => ChatPollResult(
    hasNew: json['has_new'] as bool? ?? false,
    unreadTotal: (json['unread_total'] as num?)?.toInt() ?? 0,
    shopReadUpTo: json['shop_read_up_to'] != null
        ? DateTime.tryParse(json['shop_read_up_to'] as String)
        : null,
  );
}
