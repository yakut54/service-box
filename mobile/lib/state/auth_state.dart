import 'dart:async';

import 'package:flutter/foundation.dart';

import '../data/auth_token_store.dart';
import '../models/auth_session.dart';

class AuthState extends ChangeNotifier {
  final AuthTokenStore _store;

  AuthState(this._store);

  AuthSession? _session;
  bool _loading = true;

  AuthSession? get session => _session;
  bool get isLoggedIn => _session != null;
  bool get loading => _loading;

  Future<void> load() async {
    _session = await _store.load();
    _loading = false;
    notifyListeners();
  }

  /// Ждёт, пока `load()` (чтение сессии из хранилища при старте приложения)
  /// завершится. Нужен любому экрану, который читает `session` сразу в
  /// initState — без этого `session` может оказаться null не потому что
  /// пользователь гость, а потому что чтение из хранилища ещё не успело
  /// закончиться (баг найден 2026-09-01: автозаполнение чекаута иногда не
  /// подставляло данные залогиненного покупателя — гонка именно из-за этого).
  /// Тот же приём уже был в main.dart для другого экрана (см. историю в
  /// _AppState._waitForAuthReady) — вынесено сюда, чтобы не дублировать.
  Future<void> waitUntilReady() {
    if (!_loading) return Future.value();
    final completer = Completer<void>();
    void listener() {
      if (!_loading) {
        removeListener(listener);
        if (!completer.isCompleted) completer.complete();
      }
    }

    addListener(listener);
    return completer.future;
  }

  Future<void> setSession(AuthSession session) async {
    _session = session;
    await _store.save(session);
    notifyListeners();
  }

  Future<void> logout() async {
    _session = null;
    await _store.clear();
    notifyListeners();
  }
}
