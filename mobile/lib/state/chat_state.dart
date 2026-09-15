import 'dart:async';

import 'package:flutter/widgets.dart';

import '../data/chat_realtime_client.dart';
import '../data/chat_repository.dart';

/// Счётчик непрочитанных сообщений от магазина — крутит бейдж на иконке
/// чата в каталоге. Живёт на постоянном вебсокете (см. ChatRealtimeClient),
/// пока байер вошёл в аккаунт и приложение на экране — не на опросе по
/// таймеру (баг/пожелание 2026-09-14: бейдж должен быть настоящим
/// реалтаймом, как в админке, а не обновляться раз в 15 сек).
///
/// У покупателя в этой сборке ровно один диалог (см. chat_threads —
/// уникальный constraint на customer_id), поэтому один вебсокет-канал на
/// тред фактически уже канал «на покупателя» — заводить второе соединение
/// не нужно. `ChatScreen`, пока он открыт, переиспользует ЭТО ЖЕ
/// соединение через [realtime] (addListener/removeListener), а не поднимает
/// своё — иначе было бы два сокета на один и тот же канал.
class ChatState extends ChangeNotifier with WidgetsBindingObserver {
  ChatState({ChatRepository? repository})
    : _repository = repository ?? ChatRepository.create() {
    _realtime = ChatRealtimeClient(_repository);
  }

  final ChatRepository _repository;
  late final ChatRealtimeClient _realtime;

  String? _sessionToken;
  String? _threadId;
  int _unreadTotal = 0;
  bool _started = false;

  // Пока треда ещё нет (покупатель ни разу не писал) — подписаться не на
  // что, редкий ретрай: и чтобы поймать момент появления треда (например,
  // магазин написал первым), и чтобы не терять touchSeen()/онлайн-статус
  // покупателя, пока сокета ещё нет.
  Timer? _discoveryTimer;
  static const _discoveryEvery = Duration(seconds: 60);

  // Пока сокет открыт, обычный HTTP-опрос (который раньше держал
  // customer_last_seen_at свежим) больше не идёт — шлём лёгкий presence
  // раз в минуту специально для этого, отдельно от счётчика непрочитанных.
  Timer? _presenceTimer;
  static const _presenceEvery = Duration(seconds: 60);

  ChatRealtimeClient get realtime => _realtime;
  String? get threadId => _threadId;
  int get unreadTotal => _unreadTotal;

  void start(String sessionToken) {
    if (_started && _sessionToken == sessionToken) return;
    _sessionToken = sessionToken;
    _started = true;
    WidgetsBinding.instance.addObserver(this);
    _realtime.addListener(_onRealtimeEvent);
    _bootstrap();
  }

  void stop() {
    _started = false;
    _sessionToken = null;
    _threadId = null;
    WidgetsBinding.instance.removeObserver(this);
    _realtime.removeListener(_onRealtimeEvent);
    _realtime.disconnect();
    _discoveryTimer?.cancel();
    _discoveryTimer = null;
    _presenceTimer?.cancel();
    _presenceTimer = null;
    _unreadTotal = 0;
    notifyListeners();
  }

  /// Один HTTP-запрос, чтобы узнать текущий unreadTotal и thread_id —
  /// дальше живёт вебсокет, к опросу возвращаемся только пока треда нет.
  Future<void> _bootstrap() async {
    final token = _sessionToken;
    if (token == null) return;
    try {
      final result = await _repository.poll(token);
      if (_unreadTotal != result.unreadTotal) {
        _unreadTotal = result.unreadTotal;
        notifyListeners();
      }
      _threadId = result.threadId;
    } catch (_) {
      // фоновая загрузка — молча пропускаем сетевые сбои
    }
    if (!_started) return;
    if (_threadId != null) {
      _connectSocket();
    } else {
      _discoveryTimer?.cancel();
      _discoveryTimer = Timer(_discoveryEvery, _bootstrap);
    }
  }

  void _connectSocket() {
    final token = _sessionToken;
    final threadId = _threadId;
    if (token == null || threadId == null) return;
    _discoveryTimer?.cancel();
    _discoveryTimer = null;
    _realtime.connect(threadId: threadId, sessionToken: token);
    _presenceTimer?.cancel();
    _presenceTimer = Timer.periodic(_presenceEvery, (_) {
      final t = _sessionToken;
      if (t != null) _repository.sendPresence(t, isTyping: false).catchError((_) {});
    });
  }

  /// Вызывается ChatScreen, когда тред только что появился (первое
  /// сообщение покупателя) — раньше треда/канала не существовало.
  void onThreadCreated(String threadId) {
    if (_threadId == threadId) return;
    _threadId = threadId;
    if (_started) _connectSocket();
  }

  void _onRealtimeEvent(String event, Map<String, dynamic> data) {
    if (event == 'message.new') {
      final raw = data['message'] as Map<String, dynamic>?;
      if (raw?['sender_type'] != 'shop') return;
      _unreadTotal++;
      notifyListeners();
      return;
    }

    // Магазин мог удалить как раз ещё непрочитанное сообщение — узнать
    // отсюда, было ли оно непрочитанным, нельзя (событие несёт только id),
    // поэтому просто перезапрашиваем точное значение с сервера вместо
    // попытки угадать локально (баг найден живым тестом 2026-09-15: бейдж
    // висел с устаревшим числом после удаления сообщений в админке).
    if (event == 'message.deleted') {
      _bootstrap();
    }
  }

  /// Вызывается экраном чата сразу после markRead(), чтобы бейдж не мигал
  /// «1» до следующего события.
  void clearUnread() {
    if (_unreadTotal != 0) {
      _unreadTotal = 0;
      notifyListeners();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!_started) return;
    if (state == AppLifecycleState.resumed) {
      if (_threadId != null) {
        _connectSocket();
      } else {
        _bootstrap();
      }
    } else {
      _realtime.disconnect();
      _discoveryTimer?.cancel();
      _presenceTimer?.cancel();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _realtime.removeListener(_onRealtimeEvent);
    _realtime.disconnect();
    _discoveryTimer?.cancel();
    _presenceTimer?.cancel();
    super.dispose();
  }
}
