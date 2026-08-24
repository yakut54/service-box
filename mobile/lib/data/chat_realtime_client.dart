import 'dart:async';
import 'dart:convert';

import 'package:web_socket_channel/web_socket_channel.dart';

import '../core/flavor_config.dart';
import 'chat_repository.dart';

/// Тонкий ручной клиент Pusher-протокола, которым говорит Reverb — без
/// `pusher_channels_flutter` (нативные SDK, лишняя сложность сборки) и без
/// laravel-echo (JS-only). `web_socket_channel` — официальный пакет команды
/// Flutter, чистый Dart, ничего платформенного не требует.
///
/// Если соединение обрывается/недоступно — просто не подключаемся молча,
/// экран чата и так работает через обычный polling (см. ChatScreen._poll),
/// WebSocket только ускоряет доставку, не является единственным путём.
class ChatRealtimeClient {
  ChatRealtimeClient(this._repository);

  final ChatRepository _repository;
  WebSocketChannel? _channel;
  StreamSubscription? _subscription;
  String? _channelName;
  String? _sessionToken;
  void Function(String event, Map<String, dynamic> data)? _onEvent;

  bool get isConnected => _channel != null;

  void connect({
    required String threadId,
    required String sessionToken,
    required void Function(String event, Map<String, dynamic> data) onEvent,
  }) {
    disconnect();

    _sessionToken = sessionToken;
    _onEvent = onEvent;
    _channelName =
        'private-chat.thread.${FlavorConfig.shopApiKey}.$threadId';

    final host = Uri.parse(FlavorConfig.apiBaseUrl).host;
    final wsUrl = Uri.parse(
      'wss://$host/app/${FlavorConfig.reverbAppKey}',
    );

    try {
      _channel = WebSocketChannel.connect(wsUrl);
      _subscription = _channel!.stream.listen(
        _handleRawMessage,
        onError: (_) {},
        onDone: () {},
        cancelOnError: true,
      );
    } catch (_) {
      // тихо — polling всё равно работает
    }
  }

  void disconnect() {
    _subscription?.cancel();
    _subscription = null;
    _channel?.sink.close();
    _channel = null;
    _channelName = null;
  }

  Future<void> _handleRawMessage(dynamic raw) async {
    final Map<String, dynamic> msg;
    try {
      msg = jsonDecode(raw as String) as Map<String, dynamic>;
    } catch (_) {
      return;
    }

    final event = msg['event'] as String?;
    if (event == null) return;

    final rawData = msg['data'];
    final data = rawData is String
        ? (jsonDecode(rawData) as Map<String, dynamic>? ?? {})
        : (rawData as Map<String, dynamic>? ?? {});

    if (event == 'pusher:connection_established') {
      final socketId = data['socket_id'] as String?;
      if (socketId != null) await _subscribe(socketId);
      return;
    }

    if (event.startsWith('pusher')) return; // служебные протокольные события

    _onEvent?.call(event, data);
  }

  Future<void> _subscribe(String socketId) async {
    final channel = _channel;
    final channelName = _channelName;
    final sessionToken = _sessionToken;
    if (channel == null || channelName == null || sessionToken == null) {
      return;
    }

    try {
      final auth = await _repository.broadcastAuth(
        sessionToken,
        channelName: channelName,
        socketId: socketId,
      );
      channel.sink.add(jsonEncode({
        'event': 'pusher:subscribe',
        'data': {'channel': channelName, 'auth': auth['auth']},
      }));
    } catch (_) {
      // не удалось авторизовать канал — остаёмся на polling
    }
  }
}
