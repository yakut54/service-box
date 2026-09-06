import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../data/catalog_repository.dart';
import '../../models/product.dart';
import '../../models/product_variant.dart';
import '../../state/auth_state.dart';

/// «Сообщить о поступлении» — вместо мёртвой плашки «нет в наличии»
/// (research §3, принцип 8). Требует вход по телефону; без сессии —
/// подсказываем зайти в профиль.
class NotifyBackInStockButton extends StatefulWidget {
  final Product product;
  final ProductVariant? variant;
  final bool compact;

  const NotifyBackInStockButton({
    super.key,
    required this.product,
    this.variant,
    this.compact = false,
  });

  @override
  State<NotifyBackInStockButton> createState() =>
      _NotifyBackInStockButtonState();
}

class _NotifyBackInStockButtonState extends State<NotifyBackInStockButton> {
  bool _busy = false;
  bool _done = false;

  Future<void> _subscribe() async {
    final auth = context.read<AuthState>();
    final messenger = ScaffoldMessenger.of(context);

    if (!auth.isLoggedIn) {
      messenger.showSnackBar(
        const SnackBar(
          content: Text('Войдите в профиль, чтобы получить уведомление'),
        ),
      );
      return;
    }

    setState(() => _busy = true);
    try {
      await CatalogRepository.create().notifyWhenBackInStock(
        auth.session!.sessionToken,
        widget.product.id,
        variantId: widget.variant?.id,
      );
      if (!mounted) return;
      setState(() => _done = true);
    } catch (_) {
      if (!mounted) return;
      messenger.showSnackBar(
        const SnackBar(content: Text('Не получилось. Попробуйте позже')),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_done) {
      return Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            Icons.notifications_active_outlined,
            size: 18,
            color: theme.colorScheme.primary,
          ),
          const SizedBox(width: 8),
          Text(
            'Сообщим, когда появится',
            style: theme.textTheme.labelLarge?.copyWith(
              color: theme.colorScheme.primary,
            ),
          ),
        ],
      );
    }

    return OutlinedButton.icon(
      onPressed: _busy ? null : _subscribe,
      icon: _busy
          ? const SizedBox(
              width: 16,
              height: 16,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          : const Icon(Icons.notifications_none_rounded, size: 18),
      label: const Text('Сообщить о поступлении'),
      style: OutlinedButton.styleFrom(
        minimumSize: Size.fromHeight(widget.compact ? 36 : 48),
      ),
    );
  }
}
