import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/category.dart';
import '../models/saved_shop.dart';
import '../state/cart_state.dart';
import '../state/catalog_state.dart';
import 'widgets/account_button.dart';
import 'widgets/cart_button.dart';
import 'widgets/chat_button.dart';
import 'widgets/error_view.dart';
import 'widgets/mini_cart_bar.dart';
import 'widgets/product_card.dart';
import 'widgets/shop_avatar.dart';

/// Каталог магазина этой сборки: категории + сетка товаров.
/// Фильтрация по категории и поиск отправляют запрос на сервер заново —
/// см. CatalogState.
class CatalogScreen extends StatefulWidget {
  final SavedShop shop;

  const CatalogScreen({super.key, required this.shop});

  @override
  State<CatalogScreen> createState() => _CatalogScreenState();
}

class _CatalogScreenState extends State<CatalogScreen> {
  final _searchController = TextEditingController();
  Timer? _searchDebounce;

  @override
  void dispose() {
    _searchDebounce?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String value) {
    _searchDebounce?.cancel();
    // Живой поиск по мере ввода — без задержки дёргали бы сервер на каждую
    // букву; 400мс достаточно, чтобы не бить лишними запросами, и не
    // ощущается как задержка при обычном темпе печати.
    _searchDebounce = Timer(const Duration(milliseconds: 400), () {
      context.read<CatalogState>().search(value);
    });
    setState(() {}); // обновить видимость кнопки очистки
  }

  void _clearSearch() {
    _searchDebounce?.cancel();
    _searchController.clear();
    context.read<CatalogState>().search('');
    setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final state = context.watch<CatalogState>();

    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            ShopAvatar(shop: widget.shop, size: 28),
            const SizedBox(width: 12),
            Text(widget.shop.name),
          ],
        ),
        actions: const [ChatButton(), AccountButton(), CartButton()],
      ),
      body: Stack(
        children: [
          Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
                child: TextField(
                  controller: _searchController,
                  textInputAction: TextInputAction.search,
                  decoration: InputDecoration(
                    hintText: 'Поиск по товарам',
                    prefixIcon: const Icon(Icons.search_rounded),
                    suffixIcon: _searchController.text.isEmpty
                        ? null
                        : IconButton(
                            icon: const Icon(Icons.close_rounded),
                            onPressed: _clearSearch,
                            tooltip: 'Очистить',
                          ),
                    isDense: true,
                  ),
                  onChanged: _onSearchChanged,
                  onSubmitted: (value) {
                    _searchDebounce?.cancel();
                    context.read<CatalogState>().search(value);
                  },
                ),
              ),
              if (state.categories.isNotEmpty)
                _CategoryChips(categories: state.categories),
              // Тонкая полоса загрузки при переключении фильтра/поиске —
              // товары уже на экране (пусть и старые), просто заменять их
              // на спиннер/пустой экран не нужно, но и полное молчание при
              // ощутимой задержке запроса выглядит как зависание интерфейса.
              SizedBox(
                height: 2,
                child: state.loading && state.products.isNotEmpty
                    ? const LinearProgressIndicator(minHeight: 2)
                    : null,
              ),
              const SizedBox(height: 4),
              Expanded(child: _buildBody(context, state)),
            ],
          ),
          const Positioned(left: 0, right: 0, bottom: 0, child: MiniCartBar()),
        ],
      ),
    );
  }

  Widget _buildBody(BuildContext context, CatalogState state) {
    if (state.error != null && state.products.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: ErrorView(
            error: state.error!,
            onRetry: () => context.read<CatalogState>().load(),
          ),
        ),
      );
    }

    if (state.loading && state.products.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (state.products.isEmpty) {
      return RefreshIndicator(
        onRefresh: () => context.read<CatalogState>().load(),
        child: ListView(
          children: const [
            SizedBox(height: 120),
            Center(child: Text('Товары не найдены')),
          ],
        ),
      );
    }

    final cartHasItems = context.watch<CartState>().itemCount > 0;

    // childAspectRatio задаёт единое отношение ширина/высота на всю
    // ячейку, а у карточки высота — это картинка-квадрат (растёт вместе
    // с шириной экрана) ПЛЮС подпись с кнопкой снизу (высота которых от
    // ширины экрана не зависит). Один фиксированный ratio не может
    // описать сумму «переменное + постоянное» сразу для всех устройств —
    // на широких экранах картинка растёт быстрее, чем нужно, и под
    // кнопкой остаётся пустое место (см. скрин с эмулятора). Поэтому
    // считаем высоту ячейки явно: ширина колонки (= высота картинки) +
    // фиксированная высота футера (имя + цена + кнопка, см. ProductCard).
    const gridPadding = 12.0;
    const crossAxisSpacing = 12.0;
    const cardFooterHeight = 130.0;
    final columnWidth =
        (MediaQuery.sizeOf(context).width - gridPadding * 2 - crossAxisSpacing) / 2;

    return RefreshIndicator(
      onRefresh: () => context.read<CatalogState>().load(),
      child: GridView.builder(
        padding: EdgeInsets.fromLTRB(
          gridPadding,
          gridPadding,
          gridPadding,
          cartHasItems ? miniCartBarReservedHeight : gridPadding,
        ),
        gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 2,
          mainAxisSpacing: 12,
          crossAxisSpacing: crossAxisSpacing,
          mainAxisExtent: columnWidth + cardFooterHeight,
        ),
        itemCount: state.products.length,
        itemBuilder: (context, index) =>
            ProductCard(product: state.products[index]),
      ),
    );
  }
}

class _CategoryChips extends StatelessWidget {
  final List<Category> categories;

  const _CategoryChips({required this.categories});

  @override
  Widget build(BuildContext context) {
    final state = context.watch<CatalogState>();

    return SizedBox(
      height: 40,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        children: [
          _chip(
            context,
            label: 'Все',
            selected: state.selectedCategoryId == null,
            onTap: () {
              context.read<CatalogState>().selectCategory(null);
            },
          ),
          for (final category in categories)
            _chip(
              context,
              label: category.name,
              selected: state.selectedCategoryId == category.id,
              onTap: () =>
                  context.read<CatalogState>().selectCategory(category.id),
            ),
        ],
      ),
    );
  }

  Widget _chip(
    BuildContext context, {
    required String label,
    required bool selected,
    required VoidCallback onTap,
  }) {
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: (_) => onTap(),
      ),
    );
  }
}
