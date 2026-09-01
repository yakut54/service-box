import 'package:flutter/material.dart';

/// Кнопка на всю ширину, залитая основным цветом — общий переиспользуемый
/// компонент для любого основного действия экрана: сабмит формы (с
/// индикатором загрузки), «В корзину», «Оформить заказ», «Готово». Раньше
/// один и тот же `SizedBox(width: double.infinity, child: FilledButton(...))`
/// был скопирован по отдельности в cart_screen.dart, add_to_cart_control.dart,
/// weight_cart_control.dart, out_of_stock_chip.dart — пользователь заметил
/// визуальное совпадение (2026-09-01) и попросил свести к одному компоненту.
class PrimarySubmitButton extends StatelessWidget {
  final String label;
  final IconData? icon;
  final bool loading;
  final VoidCallback? onPressed;

  const PrimarySubmitButton({
    super.key,
    required this.label,
    this.icon,
    this.loading = false,
    required this.onPressed,
  });

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return SizedBox(
        width: double.infinity,
        child: FilledButton(
          onPressed: null,
          child: const SizedBox(
            width: 20,
            height: 20,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
      );
    }

    return SizedBox(
      width: double.infinity,
      child: icon == null
          ? FilledButton(onPressed: onPressed, child: Text(label))
          : FilledButton.icon(
              onPressed: onPressed,
              icon: Icon(icon),
              label: Text(label),
            ),
    );
  }
}
