import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';

import 'package:mobile/data/auth_token_store.dart';
import 'package:mobile/data/chat_repository.dart';
import 'package:mobile/data/shop_cache.dart';
import 'package:mobile/data/shop_repository.dart';
import 'package:mobile/models/auth_session.dart';
import 'package:mobile/models/chat_message.dart';
import 'package:mobile/models/chat_page.dart';
import 'package:mobile/models/chat_poll_result.dart';
import 'package:mobile/models/saved_shop.dart';
import 'package:mobile/state/auth_state.dart';
import 'package:mobile/state/chat_state.dart';
import 'package:mobile/state/shop_state.dart';
import 'package:mobile/ui/chat_screen.dart';

/// Репозиторий чата для тестов — отдаёт заранее заданный список сообщений,
/// остальные методы тихо не падают (markRead/sendPresence/poll — no-op),
/// а те, что не нужны флоу выделения текста, бросают UnimplementedError,
/// чтобы тест явно упал, если на самом деле до них дошло.
class FakeChatRepository implements ChatRepository {
  FakeChatRepository(this.messages);

  final List<ChatMessage> messages;
  final List<String> deletedIds = [];
  String? lastClipboard;

  @override
  Future<ChatPage> fetchMessages(String sessionToken, {String? before}) async {
    return ChatPage(messages: messages, isBlockedByShop: false, threadId: 'thread-1');
  }

  @override
  Future<void> markRead(String sessionToken) async {}

  @override
  Future<void> sendPresence(String sessionToken, {required bool isTyping}) async {}

  @override
  Future<ChatPollResult> poll(String sessionToken, {String? after}) async {
    return const ChatPollResult(hasNew: false, unreadTotal: 0, threadId: 'thread-1');
  }

  @override
  Future<ChatMessage> sendMessage(
    String sessionToken, {
    String? body,
    String? imageUrl,
    required String clientMessageId,
    String? replyToMessageId,
    String? replyQuote,
  }) async {
    throw UnimplementedError('sendMessage не нужен для теста выделения текста');
  }

  @override
  Future<ChatMessage?> deleteMessage(String sessionToken, String messageId) async {
    deletedIds.add(messageId);
    return null;
  }

  @override
  Future<ChatMessage> editMessage(String sessionToken, String messageId, String body) async {
    throw UnimplementedError('editMessage не нужен для теста выделения текста');
  }

  @override
  Future<String> uploadImage(String sessionToken, List<int> bytes, String filename) async {
    throw UnimplementedError('uploadImage не нужен для теста выделения текста');
  }

  @override
  Future<Map<String, dynamic>> broadcastAuth(
    String sessionToken, {
    required String channelName,
    required String socketId,
  }) async {
    throw UnimplementedError('broadcastAuth не нужен для теста выделения текста');
  }
}

class _FakeAuthTokenStore implements AuthTokenStore {
  @override
  Future<AuthSession?> load() async => null;

  @override
  Future<void> save(AuthSession session) async {}

  @override
  Future<void> clear() async {}
}

class _FakeShopRepository implements ShopRepository {
  @override
  Future<SavedShop> fetchByCode(String appCode) async {
    throw UnimplementedError('тест выделения текста не загружает магазин');
  }
}

/// Готовое сообщение покупателя/магазина для фикстур — время и clientMessageId
/// не важны для флоу выделения текста, дефолты просто не должны повторяться.
ChatMessage buildMessage({
  required String id,
  required String body,
  bool isMine = true,
  DateTime? createdAt,
}) {
  return ChatMessage(
    id: id,
    threadId: 'thread-1',
    senderType: isMine ? 'customer' : 'shop',
    body: body,
    status: 'sent',
    createdAt: createdAt ?? DateTime(2026, 10, 18, 12, 0),
    clientMessageId: 'client-$id',
  );
}

/// Поднимает ChatScreen в памяти, без сети и без сокета: AuthState получает
/// подставную сессию (AuthTokenStore — no-op), ChatRepository подменяется
/// через конструкторский шов ChatScreen.repository, ShopState и ChatState
/// остаются в дефолтном состоянии — экрану этого достаточно, он их не грузит.
/// Последнее, что положили в буфер обмена через Clipboard.setData — flutter_test
/// не мокает канал буфера сам (в отличие от некоторых других платформенных
/// плагинов), поэтому без этого `_copySelection`/«Копировать» в меню выделения
/// падал бы с MissingPluginException.
String? lastClipboardText;

Future<FakeChatRepository> pumpChatScreen(
  WidgetTester tester, {
  required List<ChatMessage> messages,
}) async {
  lastClipboardText = null;
  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    SystemChannels.platform,
    (call) async {
      switch (call.method) {
        case 'Clipboard.setData':
          lastClipboardText = (call.arguments as Map)['text'] as String?;
          return null;
        case 'Clipboard.getData':
          return {'text': lastClipboardText};
        case 'Clipboard.hasStrings':
          return {'value': lastClipboardText != null};
        default:
          return null;
      }
    },
  );
  // audioplayers и flutter_local_notifications лезут в платформенные каналы
  // (проигрывание звука входящего сообщения, снятие плашек) — в тесте их нет,
  // оба места в самом экране уже обёрнуты в try/catch, но dispose() плеера
  // должен молча пройти, а не упасть, поэтому отвечаем null на любой вызов.
  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('xyz.luan/audioplayers'),
    (call) async => null,
  );
  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('xyz.luan/audioplayers.global'),
    (call) async => null,
  );

  final authState = AuthState(_FakeAuthTokenStore());
  await authState.setSession(
    AuthSession(
      phone: '+70000000000',
      sessionToken: 'test-session-token',
      expiresAt: DateTime.now().add(const Duration(days: 1)),
    ),
  );
  final repository = FakeChatRepository(messages);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider<AuthState>.value(value: authState),
        ChangeNotifierProvider<ChatState>.value(value: ChatState()),
        ChangeNotifierProvider<ShopState>.value(
          value: ShopState(_FakeShopRepository(), ShopCache()),
        ),
      ],
      child: MaterialApp(
        home: ChatScreen(repository: repository),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return repository;
}
