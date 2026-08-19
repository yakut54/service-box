import 'package:flutter/material.dart';

import '../../../core/phone_input_formatter.dart';
import '../../../core/validators.dart';

/// Поле «Телефон» с маской +7 (XXX) XXX-XX-XX — переиспользуемый компонент.
/// Лишние цифры физически не вводятся (см. RussianPhoneInputFormatter),
/// а не просто отклоняются текстом ошибки после отправки на сервер.
class PhoneField extends StatelessWidget {
  final TextEditingController controller;
  final TextInputAction textInputAction;

  const PhoneField({
    super.key,
    required this.controller,
    this.textInputAction = TextInputAction.next,
  });

  @override
  Widget build(BuildContext context) {
    return TextFormField(
      controller: controller,
      keyboardType: TextInputType.phone,
      textInputAction: textInputAction,
      inputFormatters: const [RussianPhoneInputFormatter()],
      decoration: const InputDecoration(
        labelText: 'Телефон',
        hintText: '+7 (900) 000-00-00',
      ),
      autovalidateMode: AutovalidateMode.onUserInteraction,
      validator: (value) {
        final trimmed = (value ?? '').trim();
        if (trimmed.isEmpty) return 'Укажите телефон';
        if (!isRussianPhoneValid(trimmed)) return 'Введите номер полностью';
        return null;
      },
    );
  }
}
