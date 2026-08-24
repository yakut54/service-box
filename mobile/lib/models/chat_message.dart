/// Сообщение чата с магазином (см. GET/POST /widget/chat, /widget/chat/messages).
class ChatMessage {
  final String id;
  final String threadId;
  final String senderType; // 'customer' | 'shop'
  final String? body;
  final String? imageUrl;
  final String status; // 'sent' | 'read'
  final DateTime createdAt;
  final String clientMessageId;
  final String? replyToMessageId;
  final ChatMessage? replyTo;
  final DateTime? editedAt;

  const ChatMessage({
    required this.id,
    required this.threadId,
    required this.senderType,
    this.body,
    this.imageUrl,
    required this.status,
    required this.createdAt,
    required this.clientMessageId,
    this.replyToMessageId,
    this.replyTo,
    this.editedAt,
  });

  bool get isMine => senderType == 'customer';

  factory ChatMessage.fromJson(Map<String, dynamic> json) => ChatMessage(
    id: json['id'] as String,
    threadId: json['thread_id'] as String,
    senderType: json['sender_type'] as String,
    body: json['body'] as String?,
    imageUrl: json['image_url'] as String?,
    status: json['status'] as String? ?? 'sent',
    createdAt: DateTime.parse(json['created_at'] as String),
    clientMessageId: json['client_message_id'] as String,
    replyToMessageId: json['reply_to_message_id'] as String?,
    replyTo: json['reply_to'] != null
        ? ChatMessage.fromJson(json['reply_to'] as Map<String, dynamic>)
        : null,
    editedAt: json['edited_at'] != null
        ? DateTime.tryParse(json['edited_at'] as String)
        : null,
  );
}
