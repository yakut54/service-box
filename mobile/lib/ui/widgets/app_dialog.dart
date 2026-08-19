import 'package:flutter/material.dart';

/// Единая обёртка диалога для всего приложения — 90% ширины экрана,
/// radius 12, свои отступы/типографика вместо дефолтного AlertDialog
/// (который зависит от длины контента и живёт своей темой). Один раз
/// оформили — дальше любой диалог (изменить имя, подтвердить удаление,
/// что угодно ещё) собирается из этих же кубиков, а не с нуля.
class AppDialog extends StatelessWidget {
  final String title;
  final Widget child;
  final List<Widget> actions;

  const AppDialog({
    super.key,
    required this.title,
    required this.child,
    required this.actions,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final width = MediaQuery.of(context).size.width * 0.9;

    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: SizedBox(
        width: width,
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: theme.textTheme.titleLarge?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 16),
              child,
              const SizedBox(height: 24),
              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                spacing: 8,
                children: actions,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Подтверждение действия (напр. «Удалить фото?») поверх AppDialog —
/// возвращает true, если байер подтвердил, иначе false (в т.ч. если
/// закрыл диалог тапом мимо).
Future<bool> showConfirmDialog(
  BuildContext context, {
  required String title,
  required String message,
  String confirmLabel = 'Удалить',
  String cancelLabel = 'Отмена',
  bool destructive = true,
}) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (ctx) {
      final theme = Theme.of(ctx);
      return AppDialog(
        title: title,
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: Text(cancelLabel),
          ),
          FilledButton(
            style: destructive
                ? FilledButton.styleFrom(
                    backgroundColor: theme.colorScheme.error,
                    foregroundColor: theme.colorScheme.onError,
                  )
                : null,
            onPressed: () => Navigator.of(ctx).pop(true),
            child: Text(confirmLabel),
          ),
        ],
        child: Text(message, style: theme.textTheme.bodyMedium),
      );
    },
  );
  return result ?? false;
}
