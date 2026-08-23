import '../core/app_exception.dart';
import '../models/chat_message.dart';
import '../models/chat_page.dart';
import '../models/chat_poll_result.dart';
import 'api_client.dart';
import 'chat_repository.dart';

class ApiChatRepository implements ChatRepository {
  final ApiClient _client = ApiClient();

  Map<String, String> _authHeaders(String sessionToken) => {
    'X-Phone-Session': sessionToken,
  };

  @override
  Future<ChatPage> fetchMessages(String sessionToken, {String? before}) async {
    final json = await _client.get(
      '/widget/chat',
      query: before != null ? {'before': before} : null,
      headers: _authHeaders(sessionToken),
    );
    return ChatPage.fromJson(json);
  }

  @override
  Future<ChatMessage> sendMessage(
    String sessionToken, {
    String? body,
    String? imageUrl,
    required String clientMessageId,
  }) async {
    final json = await _client.post('/widget/chat/messages', {
      if (body != null) 'body': body,
      if (imageUrl != null) 'image_url': imageUrl,
      'client_message_id': clientMessageId,
    }, headers: _authHeaders(sessionToken));
    final data = json['data'] as Map<String, dynamic>?;
    if (data == null) throw AppException.badResponse();
    return ChatMessage.fromJson(data);
  }

  @override
  Future<void> markRead(String sessionToken) async {
    await _client.post(
      '/widget/chat/read',
      {},
      headers: _authHeaders(sessionToken),
    );
  }

  @override
  Future<ChatPollResult> poll(String sessionToken, {String? after}) async {
    final json = await _client.get(
      '/widget/chat/poll',
      query: after != null ? {'after': after} : null,
      headers: _authHeaders(sessionToken),
    );
    return ChatPollResult.fromJson(json);
  }

  @override
  Future<String> uploadImage(
    String sessionToken,
    List<int> bytes,
    String filename,
  ) async {
    final json = await _client.uploadBytes(
      '/widget/chat/image',
      'image',
      bytes,
      filename,
      headers: _authHeaders(sessionToken),
    );
    final url = json['url'] as String?;
    if (url == null) throw AppException.badResponse();
    return url;
  }
}
