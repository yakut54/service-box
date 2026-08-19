import 'dart:async';

import 'package:flutter/material.dart';

/// Короткая вспышка успеха (галочка + текст) поверх текущего экрана —
/// показывается перед автоматическим переходом дальше (вход, оформление
/// заказа, сохранение адреса), чтобы результат действия не терялся
/// в мгновенном переключении экрана. Не завязан на конкретный экран —
/// один переиспользуемый helper, а не отдельный виджет под каждый случай.
Future<void> showSuccessFlash(
  BuildContext context, {
  required String message,
  Duration duration = const Duration(milliseconds: 700),
}) {
  final overlay = Overlay.of(context);
  final theme = Theme.of(context);
  final completer = Completer<void>();
  late OverlayEntry entry;

  entry = OverlayEntry(
    builder: (context) => Positioned.fill(
      child: IgnorePointer(
        child: Container(
          color: theme.colorScheme.surface.withValues(alpha: 0.94),
          child: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  Icons.check_circle_rounded,
                  size: 64,
                  color: theme.colorScheme.primary,
                ),
                const SizedBox(height: 12),
                Text(message, style: theme.textTheme.titleMedium),
              ],
            ),
          ),
        ),
      ),
    ),
  );

  overlay.insert(entry);
  Future.delayed(duration, () {
    entry.remove();
    completer.complete();
  });

  return completer.future;
}
