import 'package:flutter/material.dart';

/// Кнопка, залитая основным цветом — общий переиспользуемый компонент для
/// любого основного действия экрана: сабмит формы (с индикатором загрузки),
/// «В корзину», «Оформить заказ», «Готово». Раньше один и тот же
/// `SizedBox(width: double.infinity, child: FilledButton(...))` был
/// скопирован по отдельности в cart_screen.dart, add_to_cart_control.dart,
/// weight_cart_control.dart, out_of_stock_chip.dart — пользователь заметил
/// визуальное совпадение (2026-09-01) и попросил свести к одному компоненту.
class PrimarySubmitButton extends StatelessWidget {
  final String label;
  final IconData? icon;
  final bool loading;
  final VoidCallback? onPressed;

  /// false — в паре с другой кнопкой в один ряд (напр. «Отмена» / «Сохранить»
  /// в AppDialog): ширина по содержимому. IntrinsicWidth снаружи это НЕ
  /// решает — сам компонент внутри требовал width: double.infinity, и именно
  /// эту ширину IntrinsicWidth и измерял как «естественную» (баг найден
  /// 2026-09-01 живым тестом — кнопка «Сохранить» всё равно переносилась на
  /// две строки несмотря на обёртку). Дефолт true — сохраняет поведение
  /// везде, где кнопка одна на всю ширину экрана/секции.
  final bool expand;

  const PrimarySubmitButton({
    super.key,
    required this.label,
    this.icon,
    this.loading = false,
    this.expand = true,
    required this.onPressed,
  });

  @override
  Widget build(BuildContext context) {
    if (loading) {
      final button = FilledButton(
        onPressed: null,
        child: const SizedBox(
          width: 20,
          height: 20,
          child: CircularProgressIndicator(strokeWidth: 2),
        ),
      );
      return expand ? SizedBox(width: double.infinity, child: button) : button;
    }

    final button = icon == null
        ? FilledButton(onPressed: onPressed, child: Text(label))
        : FilledButton.icon(
            onPressed: onPressed,
            icon: Icon(icon),
            label: Text(label),
          );

    return expand ? SizedBox(width: double.infinity, child: button) : button;
  }
}
