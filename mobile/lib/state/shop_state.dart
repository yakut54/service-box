import 'package:flutter/foundation.dart';

import '../core/app_exception.dart';
import '../core/flavor_config.dart';
import '../data/shop_cache.dart';
import '../data/shop_repository.dart';
import '../models/saved_shop.dart';

/// Центральное состояние приложения: магазин этой сборки и статус его загрузки.
///
/// Один APK = один шопер (см. PLAN.md, «Флот Шоперов»), поэтому здесь больше
/// нет списка магазинов, переключения и добавления — только загрузка того
/// единственного магазина, чей код зашит в сборку (FlavorConfig.shopCode).
class ShopState extends ChangeNotifier {
  final ShopRepository _repository;
  final ShopCache _cache;

  ShopState(this._repository, this._cache);

  SavedShop? _shop;
  AppException? _error;
  bool _loading = true;

  SavedShop? get shop => _shop;
  AppException? get error => _error;
  bool get loading => _loading;

  /// Загружает магазин при старте: сперва отдаёт кэш (если есть — мгновенно
  /// и офлайн), затем обновляет данными с сервера.
  Future<void> load() async {
    _shop = await _cache.load();
    _loading = _shop == null;
    notifyListeners();
    await refresh();
  }

  /// Повторный запрос к серверу — для кнопки «Повторить» и pull-to-refresh.
  Future<void> refresh() async {
    if (FlavorConfig.shopCode.isEmpty) {
      _loading = false;
      _error = AppException.badResponse();
      notifyListeners();
      return;
    }

    _loading = _shop == null;
    _error = null;
    notifyListeners();

    try {
      final fresh = await _repository.fetchByCode(FlavorConfig.shopCode);
      _shop = fresh;
      await _cache.save(fresh);
    } on AppException catch (e) {
      _error = _shop == null ? e : null; // если есть кэш — тихо остаёмся на нём
    } catch (_) {
      _error = _shop == null ? AppException.unknown() : null;
    } finally {
      _loading = false;
      notifyListeners();
    }
  }
}
