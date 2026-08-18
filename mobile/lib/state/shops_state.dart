import 'package:flutter/foundation.dart';

import '../core/app_exception.dart';
import '../core/shop_code.dart';
import '../data/shop_repository.dart';
import '../data/shops_storage.dart';
import '../models/saved_shop.dart';

/// Центральное состояние приложения: список сохранённых магазинов байера
/// и то, какой из них сейчас активен. Здесь же живёт вся логика М0:
///  - М0.5: новый магазин добавляется в список, не заменяет его
///  - М0.6: переключение активного магазина
///  - М0.7: повторный скан/ввод уже известного кода не создаёт дубль
class ShopsState extends ChangeNotifier {
  final ShopRepository _repository;
  final ShopsStorage _storage;

  ShopsState(this._repository, this._storage);

  List<SavedShop> _shops = [];
  String? _activeCode;
  bool _busy = false;
  bool _loaded = false;

  bool get loaded => _loaded;
  bool get busy => _busy;
  bool get isEmpty => _shops.isEmpty;

  /// Магазины, активный — первым, дальше по времени последнего открытия.
  List<SavedShop> get shops {
    final sorted = [..._shops];
    sorted.sort((a, b) {
      if (a.appCode == _activeCode) return -1;
      if (b.appCode == _activeCode) return 1;
      final aTime = a.lastOpenedAt ?? a.addedAt;
      final bTime = b.lastOpenedAt ?? b.addedAt;
      return bTime.compareTo(aTime);
    });
    return List.unmodifiable(sorted);
  }

  SavedShop? get active {
    if (_activeCode == null) return null;
    for (final shop in _shops) {
      if (shop.appCode == _activeCode) return shop;
    }
    return null;
  }

  /// Поднимает сохранённые магазины при запуске приложения (М0.5).
  Future<void> load() async {
    _shops = await _storage.loadShops();
    _activeCode = await _storage.loadActiveCode();
    // На случай если активный код не совпадает ни с одним магазином
    // (например, магазин был удалён на другом устройстве) — берём первый.
    if (active == null && _shops.isNotEmpty) {
      _activeCode = _shops.first.appCode;
    }
    _loaded = true;
    notifyListeners();
  }

  /// Единая точка входа и для скана QR, и для ручного ввода кода.
  /// Возвращает найденный/переключённый магазин.
  Future<SavedShop> addOrSwitch(String rawInput) async {
    final code = parseShopCode(rawInput);
    if (code == null) throw AppException.invalidCode();

    // М0.7, быстрый путь: код уже сохранён — просто переключаемся,
    // сеть не трогаем. Работает мгновенно и офлайн.
    final known = _findByCode(code);
    if (known != null) {
      await _setActive(code);
      return known;
    }

    _busy = true;
    notifyListeners();
    try {
      final fresh = await _repository.fetchByCode(code);

      // М0.7, второй уровень: магазин с таким же shopId уже есть под другим
      // кодом (шопер перегенерировал app_code) — обновляем, а не дублируем.
      final existingById = _findByShopId(fresh.shopId);
      final merged = existingById != null
          ? fresh.copyWith(addedAt: existingById.addedAt)
          : fresh;

      _shops.removeWhere(
        (s) => s.shopId == fresh.shopId || s.appCode == fresh.appCode,
      );
      _shops.add(merged); // М0.5: добавляем, не заменяя список
      await _storage.saveShops(_shops);
      await _setActive(merged.appCode);
      return merged;
    } finally {
      _busy = false;
      notifyListeners();
    }
  }

  /// М0.6 — выбор магазина из списка в переключателе.
  Future<void> select(String appCode) => _setActive(appCode);

  Future<void> remove(String appCode) async {
    _shops.removeWhere((s) => s.appCode == appCode);
    await _storage.saveShops(_shops);
    if (_activeCode == appCode) {
      await _setActive(_shops.isEmpty ? null : _shops.first.appCode);
    } else {
      notifyListeners();
    }
  }

  SavedShop? _findByCode(String code) {
    for (final shop in _shops) {
      if (shop.appCode == code) return shop;
    }
    return null;
  }

  SavedShop? _findByShopId(String shopId) {
    for (final shop in _shops) {
      if (shop.shopId == shopId) return shop;
    }
    return null;
  }

  Future<void> _setActive(String? code) async {
    _activeCode = code;
    if (code != null) {
      final index = _shops.indexWhere((s) => s.appCode == code);
      if (index != -1) {
        _shops[index] = _shops[index].copyWith(lastOpenedAt: DateTime.now());
        await _storage.saveShops(_shops);
      }
    }
    await _storage.saveActiveCode(code);
    notifyListeners();
  }
}
