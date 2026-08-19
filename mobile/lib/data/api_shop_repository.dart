import '../core/app_exception.dart';
import '../core/flavor_config.dart';
import '../models/saved_shop.dart';
import 'api_client.dart';
import 'shop_repository.dart';

/// Настоящая реализация ShopRepository — берёт магазин этой сборки
/// с бэкенда через GET /widget/shop.
///
/// appCode в fetchByCode принципиально не используется как параметр запроса:
/// авторизация идёт через X-Shop-ID (api_key), который уже зашит в сборку
/// (см. FlavorConfig) и подставляется в ApiClient автоматически. Параметр
/// оставлен только чтобы совпадать с сигнатурой ShopRepository.
class ApiShopRepository implements ShopRepository {
  final ApiClient _client = ApiClient();

  @override
  Future<SavedShop> fetchByCode(String appCode) async {
    try {
      final json = await _client.get('/widget/shop');
      return SavedShop.fromWidgetShop(
        FlavorConfig.shopCode,
        FlavorConfig.shopApiKey,
        json,
      );
    } on AppException catch (e) {
      // 404 здесь всегда значит "api_key сборки невалиден" — конкретнее,
      // чем generic AppException.notFound() из ApiClient.
      if (e.kind == AppErrorKind.notFound) throw AppException.shopNotFound();
      rethrow;
    }
  }
}
