import 'chat_message.dart';

/// Страница сообщений чата вместе с текущим статусом блокировки треда
/// (см. GET /widget/chat) — `isBlockedByShop` при отсутствии треда всегда
/// false, писать в первый раз ничто не мешает. `threadId` — null, пока
/// диалог ещё не создан (ни одного сообщения не отправлено).
class ChatPage {
  final List<ChatMessage> messages;
  final bool isBlockedByShop;
  final String? threadId;

  const ChatPage({
    required this.messages,
    required this.isBlockedByShop,
    this.threadId,
  });

  factory ChatPage.fromJson(Map<String, dynamic> json) {
    final data = (json['data'] as List<dynamic>? ?? [])
        .map((e) => ChatMessage.fromJson(e as Map<String, dynamic>))
        .toList();
    final thread = json['thread'] as Map<String, dynamic>?;
    return ChatPage(
      messages: data,
      isBlockedByShop: thread?['is_blocked_by_shop'] as bool? ?? false,
      threadId: thread?['id'] as String?,
    );
  }
}
