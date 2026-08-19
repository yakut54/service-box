import 'package:flutter/material.dart';

/// Поле «Имя» — переиспользуемый компонент формы (см. Checkout, будущие
/// формы профиля/адресов). Валидация — на каждое взаимодействие, не только
/// при отправке (AutovalidateMode.onUserInteraction), совпадает по
/// требованиям с виджетом (widget/src/components/Checkout.vue: min 2 символа).
class NameField extends StatelessWidget {
  final TextEditingController controller;
  final TextInputAction textInputAction;

  const NameField({
    super.key,
    required this.controller,
    this.textInputAction = TextInputAction.next,
  });

  @override
  Widget build(BuildContext context) {
    return TextFormField(
      controller: controller,
      textInputAction: textInputAction,
      textCapitalization: TextCapitalization.words,
      autocorrect: false,
      maxLength: 100,
      decoration: const InputDecoration(labelText: 'Имя', counterText: ''),
      autovalidateMode: AutovalidateMode.onUserInteraction,
      validator: (value) {
        final trimmed = (value ?? '').trim();
        if (trimmed.isEmpty) return 'Укажите имя';
        if (trimmed.length < 2) return 'Минимум 2 символа';
        return null;
      },
    );
  }
}
