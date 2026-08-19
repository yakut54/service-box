import 'package:flutter/material.dart';

/// Кнопка отправки формы с индикатором загрузки — переиспользуемый
/// компонент (тот же паттерн понадобится в любой другой форме с
/// асинхронной отправкой).
class PrimarySubmitButton extends StatelessWidget {
  final String label;
  final bool loading;
  final VoidCallback? onPressed;

  const PrimarySubmitButton({
    super.key,
    required this.label,
    required this.loading,
    required this.onPressed,
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      child: FilledButton(
        onPressed: loading ? null : onPressed,
        child: loading
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Text(label),
      ),
    );
  }
}
