/// Сообщение чата с магазином (см. GET/POST /widget/chat, /widget/chat/messages).
class ChatMessage {
  final String id;
  final String senderType; // 'customer' | 'shop'
  final String? body;
  final String? imageUrl;
  final String status; // 'sent' | 'read'
  final DateTime createdAt;
  final String clientMessageId;

  const ChatMessage({
    required this.id,
    required this.senderType,
    this.body,
    this.imageUrl,
    required this.status,
    required this.createdAt,
    required this.clientMessageId,
  });

  bool get isMine => senderType == 'customer';

  factory ChatMessage.fromJson(Map<String, dynamic> json) => ChatMessage(
    id: json['id'] as String,
    senderType: json['sender_type'] as String,
    body: json['body'] as String?,
    imageUrl: json['image_url'] as String?,
    status: json['status'] as String? ?? 'sent',
    createdAt: DateTime.parse(json['created_at'] as String),
    clientMessageId: json['client_message_id'] as String,
  );
}
