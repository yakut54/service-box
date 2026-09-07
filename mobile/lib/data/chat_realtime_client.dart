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
/// Если соединение обрывается/недоступно — переподключаемся сами с
/// нарастающей паузой; пока сокета нет, экран чата всё равно работает через
/// обычный polling (см. ChatScreen._poll), WebSocket только ускоряет
/// доставку. Обрыв бывает штатно: Reverb закрывает молчащий сокет по
/// activity-таймауту, поэтому держим keepalive (`pusher:ping`) и отвечаем на
/// серверный `pusher:ping` — без этого сокет тихо умирал через ~2 минуты и
/// сообщения переставали приходить в открытый чат до перезахода.
class ChatRealtimeClient {
  ChatRealtimeClient(this._repository);

  final ChatRepository _repository;
  WebSocketChannel? _channel;
  StreamSubscription? _subscription;
  String? _channelName;
  String? _sessionToken;
  void Function(String event, Map<String, dynamic> data)? _onEvent;

  // Переподключение
  bool _disposed = false;
  int _retry = 0;
  Timer? _reconnectTimer;
  static const _backoff = [
    Duration(seconds: 1),
    Duration(seconds: 2),
    Duration(seconds: 5),
    Duration(seconds: 10),
    Duration(seconds: 20),
    Duration(seconds: 30),
  ];

  // Keepalive
  Timer? _heartbeatTimer;
  DateTime _lastActivity = DateTime.now();
  static const _heartbeatEvery = Duration(seconds: 25);
  bool _subscribed = false;

  bool get isConnected => _channel != null && _subscribed;

  void connect({
    required String threadId,
    required String sessionToken,
    required void Function(String event, Map<String, dynamic> data) onEvent,
  }) {
    _disposed = false;
    _reconnectTimer?.cancel();
    _reconnectTimer = null;
    _sessionToken = sessionToken;
    _onEvent = onEvent;
    _channelName = 'private-chat.thread.${FlavorConfig.shopApiKey}.$threadId';
    _retry = 0;
    _openSocket();
  }

  void disconnect() {
    _disposed = true;
    _reconnectTimer?.cancel();
    _reconnectTimer = null;
    _heartbeatTimer?.cancel();
    _heartbeatTimer = null;
    _subscription?.cancel();
    _subscription = null;
    _channel?.sink.close();
    _channel = null;
    _subscribed = false;
  }

  void _openSocket() {
    _subscription?.cancel();
    _channel?.sink.close();
    _subscribed = false;

    final host = Uri.parse(FlavorConfig.apiBaseUrl).host;
    final wsUrl = Uri.parse('wss://$host/app/${FlavorConfig.reverbAppKey}');

    try {
      final channel = WebSocketChannel.connect(wsUrl);
      _channel = channel;
      _lastActivity = DateTime.now();
      _subscription = channel.stream.listen(
        _handleRawMessage,
        onError: (_) => _scheduleReconnect(),
        onDone: _scheduleReconnect,
        cancelOnError: true,
      );
      _startHeartbeat();
    } catch (_) {
      _scheduleReconnect();
    }
  }

  void _scheduleReconnect() {
    if (_disposed) return;
    _heartbeatTimer?.cancel();
    _subscribed = false;
    _channel = null;
    if (_reconnectTimer != null) return; // уже запланировано
    final delay = _backoff[_retry.clamp(0, _backoff.length - 1)];
    _retry++;
    _reconnectTimer = Timer(delay, () {
      _reconnectTimer = null;
      if (!_disposed) _openSocket();
    });
  }

  void _startHeartbeat() {
    _heartbeatTimer?.cancel();
    _heartbeatTimer = Timer.periodic(_heartbeatEvery, (_) {
      final channel = _channel;
      if (channel == null) return;
      // Молчим только если недавно что-то приходило — иначе шлём ping, чтобы
      // Reverb не закрыл сокет по неактивности (и чтобы мы заметили мёртвое
      // соединение: pong не придёт → следующий цикл повиснет, onDone/onError
      // поднимет reconnect).
      if (DateTime.now().difference(_lastActivity) < _heartbeatEvery) return;
      try {
        channel.sink.add(jsonEncode({'event': 'pusher:ping', 'data': {}}));
      } catch (_) {
        _scheduleReconnect();
      }
    });
  }

  Future<void> _handleRawMessage(dynamic raw) async {
    _lastActivity = DateTime.now();

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
      _retry = 0; // успешный коннект — сбрасываем backoff
      final socketId = data['socket_id'] as String?;
      if (socketId != null) await _subscribe(socketId);
      return;
    }

    if (event == 'pusher:ping') {
      try {
        _channel?.sink.add(jsonEncode({'event': 'pusher:pong', 'data': {}}));
      } catch (_) {
        _scheduleReconnect();
      }
      return;
    }

    if (event == 'pusher_internal:subscription_succeeded') {
      _subscribed = true;
      return;
    }

    if (event.startsWith('pusher')) return; // прочие служебные события

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
      // не удалось авторизовать канал — переподключимся и попробуем снова
      _scheduleReconnect();
    }
  }
}
