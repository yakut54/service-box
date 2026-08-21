import 'package:flutter/material.dart';

/// Текстовое поле с живой валидацией по мере ввода — вместо ошибки,
/// которая появляется только после попытки отправить форму:
///
/// - пусто → рамка красная, снизу [emptyMessage] ("Укажите город")
/// - начали печатать, но короче [minLength] → рамка красная, снизу
///   [shortMessage] ("Не менее 2 символов")
/// - достаточно символов → подсказка исчезает, рамка зелёная
///
/// `Form.validate()` при отправке продолжает работать как обычно — поле
/// остаётся `FormField`, просто состояние показывается не только по
/// результату submit, а всегда.
class LiveValidatedField extends StatefulWidget {
  final TextEditingController controller;
  final String label;
  final String emptyMessage;
  final int minLength;
  final String? shortMessage;
  final TextInputType? keyboardType;

  const LiveValidatedField({
    super.key,
    required this.controller,
    required this.label,
    required this.emptyMessage,
    this.minLength = 1,
    this.shortMessage,
    this.keyboardType,
  });

  @override
  State<LiveValidatedField> createState() => _LiveValidatedFieldState();
}

class _LiveValidatedFieldState extends State<LiveValidatedField> {
  static const _validColor = Color(0xFF16A34A);

  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_onChanged);
  }

  @override
  void dispose() {
    widget.controller.removeListener(_onChanged);
    super.dispose();
  }

  void _onChanged() => setState(() {});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final length = widget.controller.text.trim().length;
    final isEmpty = length == 0;
    final isValid = length >= widget.minLength;

    final String? helperText;
    final Color stateColor;
    if (isEmpty) {
      helperText = widget.emptyMessage;
      stateColor = theme.colorScheme.error;
    } else if (!isValid) {
      helperText = widget.shortMessage ?? widget.emptyMessage;
      stateColor = theme.colorScheme.error;
    } else {
      helperText = null;
      stateColor = _validColor;
    }

    final baseBorder = theme.inputDecorationTheme.border;
    final borderShape = baseBorder is OutlineInputBorder
        ? baseBorder
        : const OutlineInputBorder();

    return TextFormField(
      controller: widget.controller,
      keyboardType: widget.keyboardType,
      decoration: InputDecoration(
        labelText: widget.label,
        helperText: helperText,
        helperStyle: TextStyle(color: theme.colorScheme.error),
        enabledBorder: borderShape.copyWith(
          borderSide: BorderSide(color: stateColor),
        ),
        focusedBorder: borderShape.copyWith(
          borderSide: BorderSide(color: stateColor, width: 2),
        ),
      ),
      validator: (_) => isValid ? null : (helperText ?? widget.emptyMessage),
    );
  }
}
