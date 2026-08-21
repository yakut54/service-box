import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

/// Текстовое поле с живой валидацией по мере ввода — вместо ошибки,
/// которая появляется только после попытки отправить форму:
///
/// - ещё не трогали и не пытались отправить форму → обычная серая рамка,
///   ничего не подсвечивается (см. [touchedSignal])
/// - тронули (напечатали хоть символ) и поле пусто → рамка красная, снизу
///   [emptyMessage] ("Укажите город")
/// - напечатали, но короче [minLength] → рамка красная, снизу
///   [shortMessage] ("Не менее 2 символов")
/// - достаточно символов → подсказка исчезает, рамка зелёная
///
/// `Form.validate()` при отправке продолжает работать как обычно — поле
/// остаётся `FormField`, просто состояние показывается не только по
/// результату submit, а с момента, когда поле тронули.
class LiveValidatedField extends StatefulWidget {
  final TextEditingController controller;
  final String label;
  final String emptyMessage;
  final int minLength;
  final String? shortMessage;
  final TextInputType? keyboardType;

  /// Доп. проверка формата поверх длины (например «в номере дома должна
  /// быть цифра») — вызывается только когда длина уже прошла [minLength].
  final bool Function(String trimmed)? extraCheck;
  final String? invalidMessage;

  /// Родитель увеличивает значение прямо перед `Form.validate()` — так
  /// поле начинает показывать своё состояние, даже если пользователь в
  /// него ещё не печатал, а просто нажал «Сохранить».
  final ValueListenable<int>? touchedSignal;

  const LiveValidatedField({
    super.key,
    required this.controller,
    required this.label,
    required this.emptyMessage,
    this.minLength = 1,
    this.shortMessage,
    this.keyboardType,
    this.touchedSignal,
    this.extraCheck,
    this.invalidMessage,
  });

  @override
  State<LiveValidatedField> createState() => _LiveValidatedFieldState();
}

class _LiveValidatedFieldState extends State<LiveValidatedField> {
  static const _validColor = Color(0xFF16A34A);

  bool _touched = false;

  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_onTextChanged);
    widget.touchedSignal?.addListener(_onTouchedSignal);
  }

  @override
  void dispose() {
    widget.controller.removeListener(_onTextChanged);
    widget.touchedSignal?.removeListener(_onTouchedSignal);
    super.dispose();
  }

  void _onTextChanged() => setState(() => _touched = true);

  void _onTouchedSignal() => setState(() => _touched = true);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final trimmed = widget.controller.text.trim();
    final isEmpty = trimmed.isEmpty;
    final tooShort = !isEmpty && trimmed.length < widget.minLength;
    final failsExtra =
        !isEmpty &&
        !tooShort &&
        widget.extraCheck != null &&
        !widget.extraCheck!(trimmed);
    final isValid = !isEmpty && !tooShort && !failsExtra;

    String? helperText;
    InputBorder? enabledBorder;
    InputBorder? focusedBorder;

    if (_touched) {
      final Color stateColor;
      if (isEmpty) {
        helperText = widget.emptyMessage;
        stateColor = theme.colorScheme.error;
      } else if (tooShort) {
        helperText = widget.shortMessage ?? widget.emptyMessage;
        stateColor = theme.colorScheme.error;
      } else if (failsExtra) {
        helperText = widget.invalidMessage ?? widget.emptyMessage;
        stateColor = theme.colorScheme.error;
      } else {
        helperText = null;
        stateColor = _validColor;
      }

      final baseBorder = theme.inputDecorationTheme.border;
      final borderShape = baseBorder is OutlineInputBorder
          ? baseBorder
          : const OutlineInputBorder();
      enabledBorder = borderShape.copyWith(
        borderSide: BorderSide(color: stateColor),
      );
      focusedBorder = borderShape.copyWith(
        borderSide: BorderSide(color: stateColor, width: 2),
      );
    }

    return TextFormField(
      controller: widget.controller,
      keyboardType: widget.keyboardType,
      decoration: InputDecoration(
        labelText: widget.label,
        helperText: helperText,
        helperStyle: TextStyle(color: theme.colorScheme.error),
        enabledBorder: enabledBorder,
        focusedBorder: focusedBorder,
      ),
      validator: (_) => isValid ? null : (helperText ?? widget.emptyMessage),
    );
  }
}
