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
    String? replyToMessageId,
  });

  /// Только своё сообщение, и только если магазин разрешил это в настройках
  /// (`shops.chat_customer_delete_enabled`) — сервер сам это проверяет и
  /// вернёт 403, если нет; экран просто показывает ошибку из ответа.
  Future<void> deleteMessage(String sessionToken, String messageId);

  Future<void> markRead(String sessionToken);

  /// Эфемерный пинг "печатаю"/"я тут" — не персистентный, просто пролетает
  /// в WS-канал треда (см. ChatController::presence на бэкенде,
  /// PLAN-CHAT.md). Не настоящий presence-канал, дёшево и достаточно для
  /// одного диалога байер↔магазин.
  Future<void> sendPresence(String sessionToken, {required bool isTyping});

  Future<ChatPollResult> poll(String sessionToken, {String? after});

  Future<String> uploadImage(
    String sessionToken,
    List<int> bytes,
    String filename,
  );

  /// Ручная подпись приватного WS-канала (см. PLAN-CHAT.md §12) — покупатель
  /// не Sanctum-пользователь, обычный /broadcasting/auth ему не подходит.
  Future<Map<String, dynamic>> broadcastAuth(
    String sessionToken, {
    required String channelName,
    required String socketId,
  });

  factory ChatRepository.create() => ApiChatRepository();
}
