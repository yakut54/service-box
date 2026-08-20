import 'package:flutter/material.dart';

import '../../../core/validators.dart';

/// Поле «Email» — необязательное (см. core/validators.dart и
/// widget/src/components/Checkout.vue — тот же стандарт: телефон уже
/// даёт identity, требовать ещё и email — лишнее трение в чекауте).
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
      decoration: const InputDecoration(
        labelText: 'Email (необязательно)',
        counterText: '',
      ),
      autovalidateMode: AutovalidateMode.onUserInteraction,
      validator: (value) {
        final trimmed = (value ?? '').trim();
        if (trimmed.isEmpty) return null;
        if (!isEmailValid(trimmed)) return 'Неверный формат email';
        return null;
      },
    );
  }
}
