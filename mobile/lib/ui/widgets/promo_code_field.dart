import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/app_exception.dart';
import '../../core/format.dart';
import '../../data/discount_repository.dart';
import '../../state/cart_state.dart';

/// Поле промокода в корзине: свёрнуто в ссылку «У меня есть промокод»,
/// разворачивается в поле ввода, после применения — показывает бейдж
/// скидки с кнопкой снять. Ручной промокод перекрывает автоскидку
/// (см. CartScreen._checkAutoDiscount — не трогает уже применённую).
class PromoCodeField extends StatefulWidget {
  const PromoCodeField({super.key});

  @override
  State<PromoCodeField> createState() => _PromoCodeFieldState();
}

class _PromoCodeFieldState extends State<PromoCodeField> {
  final _controller = TextEditingController();
  bool _expanded = false;
  bool _applying = false;
  AppException? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _apply() async {
    final code = _controller.text.trim();
    if (code.isEmpty) return;

    setState(() {
      _applying = true;
      _error = null;
    });

    final cart = context.read<CartState>();
    try {
      final discount = await DiscountRepository.create().validate(
        code,
        cart.totalKopecks,
        cart.items,
      );
      if (!mounted) return;
      cart.setDiscount(discount);
    } on AppException catch (e) {
      setState(() => _error = e);
    } catch (_) {
      setState(() => _error = AppException.unknown());
    } finally {
      if (mounted) setState(() => _applying = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final discount = context.watch<CartState>().discount;

    if (discount != null) {
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          color: theme.colorScheme.primaryContainer.withValues(alpha: 0.5),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Row(
          children: [
            Icon(
              Icons.local_offer_rounded,
              size: 18,
              color: theme.colorScheme.primary,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                '${discount.name}: −${formatRubles(discount.amountRubles)}',
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
            IconButton(
              visualDensity: VisualDensity.compact,
              icon: const Icon(Icons.close_rounded, size: 18),
              tooltip: 'Снять промокод',
              onPressed: () => context.read<CartState>().setDiscount(null),
            ),
          ],
        ),
      );
    }

    if (!_expanded) {
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          onPressed: () => setState(() => _expanded = true),
          icon: const Icon(Icons.local_offer_outlined, size: 18),
          label: const Text('У меня есть промокод'),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: TextField(
                controller: _controller,
                autofocus: true,
                textCapitalization: TextCapitalization.characters,
                decoration: const InputDecoration(
                  hintText: 'Промокод',
                  isDense: true,
                ),
                onSubmitted: (_) => _apply(),
              ),
            ),
            const SizedBox(width: 8),
            // FilledButton.styleFrom(minimumSize: Size.zero), не IntrinsicWidth
            // — тема задаёт кнопкам minimumSize: Size.fromHeight(52), то есть
            // ширина = infinity; IntrinsicWidth снаружи измеряет ровно эту же
            // бесконечность как "естественную" ширину, не помогает (см.
            // primary_submit_button.dart, перепроверено 2026-09-01 живым
            // тестом на реальном устройстве).
            FilledButton(
              style: FilledButton.styleFrom(minimumSize: Size.zero),
              onPressed: _applying ? null : _apply,
              child: _applying
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Применить'),
            ),
          ],
        ),
        if (_error != null) ...[
          const SizedBox(height: 4),
          Text(
            _error!.message,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
        ],
      ],
    );
  }
}
