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
