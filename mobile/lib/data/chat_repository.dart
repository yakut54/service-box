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
    /// Отрывок текста, выделенный пользователем в сообщении
    /// replyToMessageId (не весь текст) — см. PLAN.md 2026-10-18.
    /// Бессмысленна без replyToMessageId, бэкенд это тоже проверяет.
    String? replyQuote,
  });

  /// Только своё сообщение, и только если магазин разрешил это в настройках
  /// (`shops.chat_customer_delete_enabled`) — сервер сам это проверяет и
  /// вернёт 403, если нет; экран просто показывает ошибку из ответа.
  ///
  /// Две ступени (спека 2026-09-16): первый вызов оставляет «надгробие» и
  /// возвращает обновлённое сообщение (deletedAt проставлен, body/imageUrl
  /// обнулены) — экран должен ЗАМЕНИТЬ сообщение в списке, не убирать его.
  /// Повторный вызов на уже удалённом сообщении стирает его насовсем и
  /// возвращает null — вот тогда сообщение убирается из списка.
  Future<ChatMessage?> deleteMessage(String sessionToken, String messageId);

  /// Редактирование СВОЕГО сообщения — переиспользует композер на экране
  /// (см. ChatScreen._startEdit), не отдельный диалог. Сервер отклонит
  /// чужое/уже удалённое сообщение (403/422).
  Future<ChatMessage> editMessage(String sessionToken, String messageId, String body);

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
