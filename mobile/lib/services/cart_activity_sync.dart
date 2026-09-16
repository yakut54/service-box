import 'dart:async';

import '../data/cart_activity_repository.dart';
import '../state/auth_state.dart';
import '../state/cart_state.dart';

/// Синхронизирует с бэкендом только счётчик товаров в корзине и момент
/// последнего изменения (не состав корзины) — питает напоминание о
/// брошенной корзине (см. CartActivityController, SendCartReminders на
/// бэкенде). Статический сервис-класс, как FcmService/PushRouter — не
/// трогает CartState/AuthState напрямую, просто подписывается на оба через
/// уже существующий ChangeNotifier.addListener.
class CartActivitySync {
  static bool _attached = false;
  static Timer? _debounce;
  static int? _lastSyncedCount;
  static bool _wasLoggedIn = false;

  static void attach(CartState cart, AuthState auth) {
    if (_attached) return;
    _attached = true;

    cart.addListener(() => _schedule(cart, auth));

    // Один слушатель покрывает и восстановление сессии на холодном старте
    // (AuthState.load()), и обычный вход (setSession()), и выход (logout())
    // — все три места уже вызывают notifyListeners().
    auth.addListener(() {
      final loggedIn = auth.isLoggedIn;
      if (loggedIn && !_wasLoggedIn) {
        _wasLoggedIn = true;
        _sync(cart, auth); // подхватить корзину, собранную ещё до входа
      } else if (!loggedIn && _wasLoggedIn) {
        _wasLoggedIn = false;
        _debounce?.cancel();
        _lastSyncedCount = null;
      }
    });
  }

  static void _schedule(CartState cart, AuthState auth) {
    if (!auth.isLoggedIn) return; // гость — сессии для запроса нет
    if (cart.itemCount == _lastSyncedCount) {
      return; // например, сработало из-за пересчёта скидки, не состава корзины
    }
    _debounce?.cancel();
    _debounce = Timer(const Duration(seconds: 3), () => _sync(cart, auth));
  }

  static Future<void> _sync(CartState cart, AuthState auth) async {
    final token = auth.session?.sessionToken;
    if (token == null) return;
    final n = cart.itemCount;
    if (n == _lastSyncedCount) return;
    try {
      await CartActivityRepository.create().updateActivity(token, n);
      _lastSyncedCount = n;
    } catch (_) {
      // не критично — синхронизируется на следующем изменении корзины
    }
  }
}
