import 'package:flutter/material.dart';

import '../../../core/validators.dart';

/// Поле «Email» — переиспользуемый компонент формы. Тот же regex, что
/// и в веб-виджете (см. core/validators.dart).
class EmailField extends StatelessWidget {
  final TextEditingController controller;
  final TextInputAction textInputAction;

  const EmailField({
    super.key,
    required this.controller,
    this.textInputAction = TextInputAction.done,
  });

  @override
  Widget build(BuildContext context) {
    return TextFormField(
      controller: controller,
      keyboardType: TextInputType.emailAddress,
      textInputAction: textInputAction,
      autocorrect: false,
      maxLength: 254,
      decoration: const InputDecoration(labelText: 'Email', counterText: ''),
      autovalidateMode: AutovalidateMode.onUserInteraction,
      validator: (value) {
        final trimmed = (value ?? '').trim();
        if (trimmed.isEmpty) return 'Укажите email';
        if (!isEmailValid(trimmed)) return 'Неверный формат email';
        return null;
      },
    );
  }
}
