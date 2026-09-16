import 'package:flutter/material.dart';

import 'skeleton.dart';

/// Skeleton сетки каталога — первая загрузка (catalog_screen.dart) и загрузка
/// магазина на старте приложения (main.dart, паттерн «app shell»: сразу видна
/// форма приложения, а не спиннер). Два потребителя — по правилу проекта
/// («второй потребитель — выносим») это единственный skeleton экрана,
/// вынесенный в отдельный публичный файл, а не приватный класс внутри экрана.
///
/// Геометрия ячейки — константы отсюда, не дублируются в catalog_screen.dart,
/// чтобы skeleton не разъехался с реальной сеткой при будущей правке.
class ProductGridSkeleton extends StatelessWidget {
  static const gridPadding = 8.0;
  static const crossAxisSpacing = 4.0;
  static const mainAxisSpacing = 4.0;
  // Имя (2 строки) + рейтинг + цена + «₽/шт» + кнопка — см. ProductCard.
  static const cardFooterHeight = 158.0;

  final int itemCount;
  final EdgeInsets? padding;

  const ProductGridSkeleton({super.key, this.itemCount = 6, this.padding});

  @override
  Widget build(BuildContext context) {
    final columnWidth =
        (MediaQuery.sizeOf(context).width - gridPadding * 2 - crossAxisSpacing) / 2;

    return Shimmer(
      child: GridView.builder(
        padding: padding ?? const EdgeInsets.all(gridPadding),
        physics: const NeverScrollableScrollPhysics(),
        gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 2,
          mainAxisSpacing: mainAxisSpacing,
          crossAxisSpacing: crossAxisSpacing,
          mainAxisExtent: columnWidth + cardFooterHeight,
        ),
        itemCount: itemCount,
        itemBuilder: (context, index) => const _ProductCardSkeleton(),
      ),
    );
  }
}

/// Повторяет форму ProductCard (widgets/product_card.dart) — квадрат-фото,
/// те же слоты фиксированной высоты в подвале, та же кнопка внизу.
class _ProductCardSkeleton extends StatelessWidget {
  const _ProductCardSkeleton();

  @override
  Widget build(BuildContext context) {
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const AspectRatio(aspectRatio: 1, child: SkeletonBox(height: double.infinity)),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(10, 8, 10, 0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SkeletonBox(height: 34),
                  const SizedBox(height: 3),
                  SkeletonBox(width: 80, height: 16),
                  const SizedBox(height: 6),
                  SkeletonBox(width: 60, height: 18),
                  const SizedBox(height: 4),
                  SkeletonBox(width: 50, height: 14),
                ],
              ),
            ),
          ),
          const Padding(
            padding: EdgeInsets.fromLTRB(6, 8, 6, 10),
            child: SkeletonBox(height: 36, borderRadius: BorderRadius.all(Radius.circular(10))),
          ),
        ],
      ),
    );
  }
}
