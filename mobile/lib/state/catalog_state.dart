import 'package:flutter/foundation.dart' hide Category;

import '../core/app_exception.dart';
import '../data/catalog_repository.dart';
import '../models/category.dart';
import '../models/product.dart';

/// Состояние каталога: категории + отфильтрованный список товаров.
/// Фильтрация по категории и поиск идут на сервер (см. CatalogRepository),
/// а не на клиенте — товаров может быть много.
class CatalogState extends ChangeNotifier {
  final CatalogRepository _repository;

  CatalogState(this._repository);

  List<Category> _categories = [];
  List<Product> _products = [];
  String? _selectedCategoryId;
  String _search = '';
  bool _loading = true;
  AppException? _error;

  List<Category> get categories => _categories;
  List<Product> get products => _products;
  String? get selectedCategoryId => _selectedCategoryId;
  bool get loading => _loading;
  AppException? get error => _error;

  /// Полная загрузка каталога — стартовое открытие экрана и pull-to-refresh
  /// (см. RefreshIndicator в catalog_screen.dart). Уважает текущий фильтр
  /// категории/поиск: раньше запрашивала товары без categoryId/search,
  /// поэтому потяни-обновить сбрасывало список на «Все», хотя чип категории
  /// в шапке продолжал показывать выбранную категорию (баг найден 2026-08-21).
  Future<void> load() async {
    _loading = true;
    _error = null;
    notifyListeners();
    try {
      final categories = await _repository.fetchCategories();
      final products = await _repository.fetchProducts(
        categoryId: _selectedCategoryId,
        search: _search,
      );
      _categories = categories;
      _products = products;
    } on AppException catch (e) {
      _error = e;
    } catch (_) {
      _error = AppException.unknown();
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  Future<void> selectCategory(String? categoryId) {
    _selectedCategoryId = categoryId;
    return _reloadProducts();
  }

  Future<void> search(String query) {
    _search = query.trim();
    return _reloadProducts();
  }

  Future<void> _reloadProducts() async {
    _loading = true;
    _error = null;
    notifyListeners();
    try {
      _products = await _repository.fetchProducts(
        categoryId: _selectedCategoryId,
        search: _search,
      );
    } on AppException catch (e) {
      _error = e;
    } catch (_) {
      _error = AppException.unknown();
    } finally {
      _loading = false;
      notifyListeners();
    }
  }
}
