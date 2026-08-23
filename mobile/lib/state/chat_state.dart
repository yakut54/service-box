import 'dart:async';

import 'package:flutter/foundation.dart';

import '../data/chat_repository.dart';

/// Счётчик непрочитанных сообщений от магазина — крутит бейдж на иконке
/// чата в каталоге, пока сам экран чата не открыт. Опрашивает только пока
/// байер вошёл в аккаунт (сессия обязательна для /widget/chat/*).
class ChatState extends ChangeNotifier {
  ChatState({ChatRepository? repository})
    : _repository = repository ?? ChatRepository.create();

  final ChatRepository _repository;
  Timer? _timer;
  int _unreadTotal = 0;

  int get unreadTotal => _unreadTotal;

  void startPolling(String sessionToken) {
    _timer?.cancel();
    _poll(sessionToken);
    _timer = Timer.periodic(
      const Duration(seconds: 15),
      (_) => _poll(sessionToken),
    );
  }

  void stopPolling() {
    _timer?.cancel();
    _timer = null;
    _unreadTotal = 0;
    notifyListeners();
  }

  Future<void> _poll(String sessionToken) async {
    try {
      final result = await _repository.poll(sessionToken);
      if (_unreadTotal != result.unreadTotal) {
        _unreadTotal = result.unreadTotal;
        notifyListeners();
      }
    } catch (_) {
      // фоновый опрос — молча пропускаем сетевые сбои
    }
  }

  /// Вызывается экраном чата сразу после markRead(), чтобы бейдж не мигал
  /// «1» ещё пятнадцать секунд до следующего тика таймера.
  void clearUnread() {
    if (_unreadTotal != 0) {
      _unreadTotal = 0;
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}
