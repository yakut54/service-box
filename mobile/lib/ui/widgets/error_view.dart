import 'package:flutter/material.dart';

import '../../core/app_exception.dart';

/// Единый блок показа ошибки — иконка, текст, подсказка, до двух кнопок.
/// Используется на экране загрузки магазина и на будущих экранах каталога,
/// чтобы не плодить одинаковую вёрстку в разных местах (правило проекта —
/// избегать копипасты).
class ErrorView extends StatelessWidget {
  final AppException error;
  final VoidCallback? onRetry;
  final String? secondaryLabel;
  final VoidCallback? onSecondary;

  const ErrorView({
    super.key,
    required this.error,
    this.onRetry,
    this.secondaryLabel,
    this.onSecondary,
  });

  IconData get _icon {
    switch (error.kind) {
      case AppErrorKind.shopNotFound:
        return Icons.storefront_outlined;
      case AppErrorKind.notFound:
        return Icons.search_off_rounded;
      case AppErrorKind.network:
      case AppErrorKind.server:
        return Icons.wifi_off_rounded;
      case AppErrorKind.tooManyRequests:
        return Icons.hourglass_empty_rounded;
      case AppErrorKind.badResponse:
      case AppErrorKind.unknown:
        return Icons.error_outline_rounded;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(_icon, size: 48, color: Theme.of(context).colorScheme.error),
        const SizedBox(height: 12),
        Text(
          error.message,
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        if (error.hint != null) ...[
          const SizedBox(height: 4),
          Text(
            error.hint!,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
        ],
        if (onRetry != null) ...[
          const SizedBox(height: 16),
          FilledButton(onPressed: onRetry, child: const Text('Повторить')),
        ],
        if (secondaryLabel != null && onSecondary != null) ...[
          const SizedBox(height: 8),
          TextButton(onPressed: onSecondary, child: Text(secondaryLabel!)),
        ],
      ],
    );
  }
}
