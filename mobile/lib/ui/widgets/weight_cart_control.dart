import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/format.dart';
import '../../models/product.dart';
import '../../state/cart_state.dart';
import 'out_of_stock_chip.dart';

/// Остатка не хватает даже на минимальную порцию — товар эффективно «нет в
/// наличии», хотя формально stockWeightGrams может быть больше нуля.
/// weightVariable и allow_backorder не проверяем — там остаток либо не
/// отслеживается (сама фича перевзвешивания не построена), либо магазин
/// сознательно снял ограничение.
bool _isOutOfStock(Product product) {
  final physical = product.physical;
  if (physical == null || physical.saleMode != ProductSaleMode.weightFixed) return false;
  if (physical.allowBackorder) return false;
  return physical.stockWeightGrams < physical.weightMinGrams;
}

/// Верхняя граница ползунка — обычно weightMaxGrams, но если на складе
/// осталось меньше (и остаток вообще отслеживается) — не даём заказать
/// больше, чем реально есть.
int _effectiveMaxGrams(Product product) {
  final physical = product.physical!;
  if (physical.saleMode != ProductSaleMode.weightFixed || physical.allowBackorder) {
    return physical.weightMaxGrams;
  }
  return physical.weightMaxGrams < physical.stockWeightGrams
      ? physical.weightMaxGrams
      : physical.stockWeightGrams;
}

/// Аналог AddToCartControl, но для товара «по весу» (см. PLAN.md, «Развесной
/// товар — финальное ТЗ»): вместо степпера +/- — ползунок веса. Один слайдер
/// на товар (двигаешь = задаёшь итоговый вес, не накапливаешь как со
/// штучным товаром). Compact-версия (карточка в сетке каталога) слайдер не
/// показывает — слишком узко, вместо этого открывает bottom sheet с ним.
class WeightCartControl extends StatelessWidget {
  final Product product;
  final bool compact;

  /// Стиль «уже в корзине» (когда weight != null) для compact-режима:
  /// каталожная карточка красит его в основной цвет, как кнопку «в
  /// корзину» рядом (see AddToCartControl); в корзине же соседний степпер
  /// штучных товаров (_CartQuantityStepper, cart_screen.dart) — светлый, с
  /// рамкой, без заливки. outlined:true повторяет именно этот вид, чтобы
  /// весовая позиция не выделялась в списке корзины вперемешку со штучными.
  final bool outlined;

  const WeightCartControl({
    super.key,
    required this.product,
    this.compact = false,
    this.outlined = false,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_isOutOfStock(product)) {
      return OutOfStockChip(compact: compact, theme: theme);
    }

    final weight = context.watch<CartState>().weightGramsOf(product.id);

    if (compact) {
      return _CompactWeightChip(
        product: product,
        weightGrams: weight,
        theme: theme,
        outlined: outlined,
      );
    }

    return _InlineWeightSlider(product: product, weightGrams: weight, theme: theme);
  }
}

void _openWeightSheet(BuildContext context, Product product) {
  showModalBottomSheet(
    context: context,
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
    ),
    builder: (sheetContext) => Padding(
      padding: EdgeInsets.only(
        left: 20, right: 20, top: 20,
        bottom: 20 + MediaQuery.of(sheetContext).viewInsets.bottom,
      ),
      child: ChangeNotifierProvider.value(
        value: context.read<CartState>(),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(product.name, style: Theme.of(sheetContext).textTheme.titleMedium, textAlign: TextAlign.center),
            const SizedBox(height: 16),
            _InlineWeightSlider(
              product: product,
              weightGrams: sheetContext.watch<CartState>().weightGramsOf(product.id),
              theme: Theme.of(sheetContext),
              onDone: () => Navigator.of(sheetContext).pop(),
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    ),
  );
}

class _CompactWeightChip extends StatelessWidget {
  final Product product;
  final int? weightGrams;
  final ThemeData theme;
  final bool outlined;

  const _CompactWeightChip({
    required this.product,
    required this.weightGrams,
    required this.theme,
    this.outlined = false,
  });

  @override
  Widget build(BuildContext context) {
    if (weightGrams == null) {
      return SizedBox(
        width: double.infinity,
        height: 36,
        child: Material(
          color: theme.colorScheme.primary,
          borderRadius: BorderRadius.circular(10),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: () => context.read<CartState>().setWeight(
              product,
              product.physical!.weightMinGrams,
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.add_shopping_cart_rounded, size: 16, color: theme.colorScheme.onPrimary),
                const SizedBox(width: 6),
                Text(
                  'В корзину',
                  style: theme.textTheme.labelMedium?.copyWith(
                    color: theme.colorScheme.onPrimary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    }

    // «Уже в корзине» — в корзине (outlined:true) должно выглядеть как
    // соседний степпер штучных товаров (_CartQuantityStepper): светлый
    // фон, тонкая рамка, обычный цвет текста — не жирная заливка кнопки
    // «добавить», иначе весовая позиция визуально выбивается из списка.
    final textColor = outlined ? theme.colorScheme.onSurface : theme.colorScheme.onPrimary;

    final chip = Material(
      color: outlined ? Colors.transparent : theme.colorScheme.primary,
      borderRadius: BorderRadius.circular(10),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => _openWeightSheet(context, product),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
          child: Center(
            child: Text(
              '${_formatGrams(weightGrams!)} · ${formatRubles(product.priceForWeightGrams(weightGrams!) / 100)}',
              // Большой макс. вес (например 1000 кг) даёт длинную строку,
              // которая переносится на 2 строки в узком чипе — без
              // textAlign.center вторая строка липнет к левому краю вместо
              // центра под первой (баг найден 2026-08-25 живым тестом).
              textAlign: TextAlign.center,
              style: theme.textTheme.labelMedium?.copyWith(
                color: textColor,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ),
      ),
    );

    return ConstrainedBox(
      constraints: const BoxConstraints(minHeight: 36),
      child: SizedBox(
        width: double.infinity,
        child: outlined
          ? DecoratedBox(
              decoration: BoxDecoration(
                border: Border.all(color: theme.colorScheme.outlineVariant),
                borderRadius: BorderRadius.circular(10),
              ),
              child: chip,
            )
          : chip,
      ),
    );
  }
}

class _InlineWeightSlider extends StatefulWidget {
  final Product product;
  final int? weightGrams;
  final ThemeData theme;

  /// Не null только в bottom sheet (см. _openWeightSheet) — на странице
  /// товара это просто часть карточки, закрывать нечего.
  final VoidCallback? onDone;

  const _InlineWeightSlider({
    required this.product,
    required this.weightGrams,
    required this.theme,
    this.onDone,
  });

  @override
  State<_InlineWeightSlider> createState() => _InlineWeightSliderState();
}

class _InlineWeightSliderState extends State<_InlineWeightSlider> {
  late final TextEditingController _controller;
  late final FocusNode _focusNode;

  int get _currentValue => widget.weightGrams ?? widget.product.physical!.weightMinGrams;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: _currentValue.toString());
    _focusNode = FocusNode();
    _focusNode.addListener(() {
      if (!_focusNode.hasFocus) _applyTypedValue();
    });
  }

  @override
  void didUpdateWidget(covariant _InlineWeightSlider oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Не перезаписываем поле, пока в нём набранное значение и так совпадает
    // (в т.ч. когда его туда вписал сам юзер) — иначе курсор скачет на
    // каждый ререндер от слайдера.
    if (!_focusNode.hasFocus && int.tryParse(_controller.text) != _currentValue) {
      _controller.text = _currentValue.toString();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    _focusNode.dispose();
    super.dispose();
  }

  void _applyGrams(int grams) {
    final physical = widget.product.physical!;
    final clamped = grams.clamp(physical.weightMinGrams, _effectiveMaxGrams(widget.product));
    context.read<CartState>().setWeight(widget.product, clamped);
  }

  void _applyTypedValue() {
    final parsed = int.tryParse(_controller.text.trim());
    if (parsed != null) {
      _applyGrams(parsed);
    } else {
      // Пусто/не число — откатываем поле на текущий вес, а не оставляем
      // висеть невалидный текст.
      _controller.text = _currentValue.toString();
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = widget.theme;
    final product = widget.product;
    final physical = product.physical!;
    final value = _currentValue;
    final maxGrams = _effectiveMaxGrams(product);
    final divisions = ((maxGrams - physical.weightMinGrams) / physical.weightStepGrams)
        .round()
        .clamp(1, 1000);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              _formatGrams(value),
              style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
            ),
            Text(
              formatRubles(product.priceForWeightGrams(value) / 100),
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w700,
                color: theme.colorScheme.primary,
              ),
            ),
          ],
        ),
        if (product.physical!.saleMode == ProductSaleMode.weightVariable)
          Padding(
            padding: const EdgeInsets.only(top: 2, bottom: 4),
            child: Text(
              'Примерная цена — финал по факту взвешивания',
              style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          ),
        Slider(
          value: value.toDouble(),
          min: physical.weightMinGrams.toDouble(),
          max: maxGrams.toDouble(),
          divisions: divisions,
          label: _formatGrams(value),
          onChanged: (v) => _applyGrams(v.round()),
        ),
        // Ручной ввод — на большом диапазоне (например до 1000 кг) точно
        // попасть в нужный вес одним слайдером почти нереально (баг найден
        // 2026-08-25 живым тестом).
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            SizedBox(
              width: 120,
              child: TextField(
                controller: _controller,
                focusNode: _focusNode,
                keyboardType: TextInputType.number,
                textAlign: TextAlign.center,
                decoration: const InputDecoration(
                  isDense: true,
                  suffixText: 'г',
                  border: OutlineInputBorder(),
                ),
                onSubmitted: (_) => _applyTypedValue(),
              ),
            ),
          ],
        ),
        if (widget.weightGrams != null)
          Align(
            alignment: Alignment.center,
            child: TextButton.icon(
              onPressed: () => context.read<CartState>().remove(product.id),
              icon: const Icon(Icons.delete_outline_rounded, size: 18),
              label: const Text('Убрать из корзины'),
            ),
          ),
        if (widget.onDone != null) ...[
          const SizedBox(height: 4),
          FilledButton(onPressed: widget.onDone, child: const Text('Готово')),
        ],
      ],
    );
  }
}

String _formatGrams(int grams) =>
    grams >= 1000 ? '${(grams / 1000).toStringAsFixed(grams % 1000 == 0 ? 0 : 2)} кг' : '$grams г';
