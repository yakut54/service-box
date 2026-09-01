import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/format.dart';
import '../../models/product.dart';
import '../../state/cart_state.dart';
import 'primary_submit_button.dart';
import 'out_of_stock_chip.dart';

/// Остатка не хватает даже на минимальную порцию — товар эффективно «нет в
/// наличии», хотя формально stockWeightGrams может быть больше нуля.
/// weightVariable и allow_backorder не проверяем — там остаток либо не
/// enforce'ится на этапе заказа (см. Product.inStock — списание для
/// weightVariable происходит позже, при взвешивании), либо магазин
/// сознательно снял ограничение.
bool _isOutOfStock(Product product) {
  final physical = product.physical;
  if (physical == null || physical.saleMode != ProductSaleMode.weightFixed) return false;
  if (physical.allowBackorder) return false;
  return physical.stockWeightGrams < physical.weightMinGrams;
}

/// Граммы → строка для РУЧНОГО ВВОДА в кг (без единицы измерения — она в
/// suffixText поля, и без округления до кг, в отличие от formatWeight,
/// который для показа скругляет до 1 знака после запятой — тут нужна полная
/// точность, иначе шаг слайдера в 100г не введёшь вручную как "0.1").
String _gramsToKgInputString(int grams) {
  final kg = grams / 1000;
  final text = kg.toStringAsFixed(3);
  // Срезаем незначащие нули: "30.000" → "30", "1.500" → "1.5"
  return text.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
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
/// штучным товаром). Слайдер сам по себе нигде не показывается инлайн — ни
/// compact (карточка в сетке), ни обычная версия (нижняя панель страницы
/// товара) не рисуют его на странице напрямую, обе только кнопка/чип,
/// открывающие общую шторку (_openWeightSheet) с этим слайдером внутри.
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

    return _WeightSummaryButton(product: product, weightGrams: weight, theme: theme);
  }
}

/// Полноразмерная кнопка для нижней панели страницы товара — раньше здесь
/// был сразу развёрнутый `_InlineWeightSlider` (бегунок, поле ввода,
/// «Убрать из корзины» — всё разом на странице), пользователь справедливо
/// назвал это «порнухой» (2026-09-01, живой тест). Теперь как у штучного
/// товара — одна кнопка, тап открывает уже готовую шторку с тем же
/// слайдером (_openWeightSheet).
class _WeightSummaryButton extends StatelessWidget {
  final Product product;
  final int? weightGrams;
  final ThemeData theme;

  const _WeightSummaryButton({
    required this.product,
    required this.weightGrams,
    required this.theme,
  });

  @override
  Widget build(BuildContext context) {
    if (weightGrams == null) {
      return PrimarySubmitButton(
        label: 'В корзину',
        icon: Icons.add_shopping_cart_rounded,
        onPressed: () => context.read<CartState>().setWeight(
          product,
          product.physical!.weightMinGrams,
        ),
      );
    }

    return PrimarySubmitButton(
      label: '${formatWeight(weightGrams!)} · ${formatRubles(product.priceForWeightGrams(weightGrams!) / 100)}',
      onPressed: () => _openWeightSheet(context, product),
    );
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
              '${formatWeight(weightGrams!)} · ${formatRubles(product.priceForWeightGrams(weightGrams!) / 100)}',
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

  /// Единица ручного ввода: кг для крупных диапазонов (максимум ≥ 1 кг, как
  /// в «Капуста» — 30 000 г одним числом нечитаемо), граммы — для мелких
  /// (специи/орехи, где шаг измеряется в десятках грамм и кг только мешал бы
  /// точности). Порог — по максимуму слайдера, не по текущему значению,
  /// чтобы единица не переключалась на лету при движении ползунка.
  bool get _useKg => _effectiveMaxGrams(widget.product) >= 1000;

  String _formatForInput(int grams) =>
      _useKg ? _gramsToKgInputString(grams) : grams.toString();

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: _formatForInput(_currentValue));
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
    if (!_focusNode.hasFocus && _parseInput(_controller.text) != _currentValue) {
      _controller.text = _formatForInput(_currentValue);
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

  /// Кг вводится через запятую или точку (100,5 / 100.5) — оба варианта
  /// привычны на русской и системной раскладке клавиатуры.
  int? _parseInput(String text) {
    final trimmed = text.trim();
    if (trimmed.isEmpty) return null;
    if (!_useKg) return int.tryParse(trimmed);
    final kg = double.tryParse(trimmed.replaceAll(',', '.'));
    return kg == null ? null : (kg * 1000).round();
  }

  void _applyTypedValue() {
    final parsed = _parseInput(_controller.text);
    if (parsed != null) {
      _applyGrams(parsed);
    } else {
      // Пусто/не число — откатываем поле на текущий вес, а не оставляем
      // висеть невалидный текст.
      _controller.text = _formatForInput(_currentValue);
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
              formatWeight(value),
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
          label: formatWeight(value),
          onChanged: (v) => _applyGrams(v.round()),
        ),
        // Ручной ввод — на большом диапазоне (например до 1000 кг) точно
        // попасть в нужный вес одним слайдером почти нереально (баг найден
        // 2026-08-25 живым тестом).
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            IconButton.filledTonal(
              onPressed: value > physical.weightMinGrams
                  ? () => _applyGrams(value - physical.weightStepGrams)
                  : null,
              icon: const Icon(Icons.remove_rounded),
            ),
            const SizedBox(width: 8),
            SizedBox(
              width: 120,
              child: TextField(
                controller: _controller,
                focusNode: _focusNode,
                keyboardType: TextInputType.numberWithOptions(decimal: _useKg),
                textAlign: TextAlign.center,
                decoration: InputDecoration(
                  isDense: true,
                  suffixText: _useKg ? 'кг' : 'г',
                  border: const OutlineInputBorder(),
                ),
                onSubmitted: (_) => _applyTypedValue(),
              ),
            ),
            const SizedBox(width: 8),
            IconButton.filledTonal(
              onPressed: value < maxGrams
                  ? () => _applyGrams(value + physical.weightStepGrams)
                  : null,
              icon: const Icon(Icons.add_rounded),
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
