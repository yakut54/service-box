import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/category.dart';
import '../models/saved_shop.dart';
import '../state/catalog_state.dart';
import 'widgets/cart_button.dart';
import 'widgets/error_view.dart';
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

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
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
        actions: const [CartButton()],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: TextField(
              controller: _searchController,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                hintText: 'Поиск по товарам',
                prefixIcon: const Icon(Icons.search_rounded),
                isDense: true,
              ),
              onSubmitted: (value) =>
                  context.read<CatalogState>().search(value),
            ),
          ),
          if (state.categories.isNotEmpty)
            _CategoryChips(categories: state.categories),
          const SizedBox(height: 4),
          Expanded(child: _buildBody(context, state)),
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

    return RefreshIndicator(
      onRefresh: () => context.read<CatalogState>().load(),
      child: GridView.builder(
        padding: const EdgeInsets.all(12),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 2,
          mainAxisSpacing: 12,
          crossAxisSpacing: 12,
          childAspectRatio: 0.68,
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
