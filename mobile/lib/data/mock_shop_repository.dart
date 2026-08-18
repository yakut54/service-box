import '../core/app_exception.dart';
import '../models/saved_shop.dart';
import 'shop_repository.dart';

/// Заглушка вместо бэкенда: три тестовых магазина с разными цветами,
/// чтобы визуально было видно, что переключение магазина меняет тему.
///
/// Формат ответа скопирован с будущего контракта GET /app/{code}
/// (см. PLAN.md, раздел М0) — когда бэкенд появится, ApiShopRepository
/// должен отдавать данные в такой же форме.
class MockShopRepository implements ShopRepository {
  static final Map<String, Map<String, dynamic>> _shops = {
    'FRUIT7': {
      'app_code': 'FRUIT7',
      'api_key': '11111111-1111-4111-8111-111111111111',
      'name': 'Фрукты 24',
      'widget_config': {
        'preset': 'light',
        'primary_color': '#16A34A',
        'border_radius': 12,
      },
      'timezone': 'Asia/Yakutsk',
    },
    'COFFEE1': {
      'app_code': 'COFFEE1',
      'api_key': '22222222-2222-4222-8222-222222222222',
      'name': 'Кофейня «Тепло»',
      'widget_config': {
        'preset': 'light',
        'primary_color': '#B45309',
        'border_radius': 16,
      },
      'timezone': 'Asia/Yakutsk',
    },
    'TECH99': {
      'app_code': 'TECH99',
      'api_key': '33333333-3333-4333-8333-333333333333',
      'name': 'ТехноМаркет',
      'widget_config': {
        'preset': 'light',
        'primary_color': '#2563EB',
        'border_radius': 4,
      },
      'timezone': 'Asia/Yakutsk',
    },
  };

  @override
  Future<SavedShop> fetchByCode(String appCode) async {
    await Future.delayed(const Duration(milliseconds: 700));

    final json = _shops[appCode];
    if (json == null) throw AppException.shopNotFound();

    return SavedShop.fromApi(appCode, json);
  }
}
