import '../models/chat_message.dart';
import '../models/chat_page.dart';
import '../models/chat_poll_result.dart';
import 'api_chat_repository.dart';

/// Чат байера с магазином — один диалог на покупателя, только для вошедших
/// по номеру телефона (см. X-Phone-Session, AuthState.session).
abstract class ChatRepository {
  Future<ChatPage> fetchMessages(String sessionToken, {String? before});

  Future<ChatMessage> sendMessage(
    String sessionToken, {
    String? body,
    String? imageUrl,
    required String clientMessageId,
  });

  Future<void> markRead(String sessionToken);

  Future<ChatPollResult> poll(String sessionToken, {String? after});

  Future<String> uploadImage(
    String sessionToken,
    List<int> bytes,
    String filename,
  );

  factory ChatRepository.create() => ApiChatRepository();
}
