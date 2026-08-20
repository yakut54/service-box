import 'package:flutter/material.dart';

enum DeliveryMethod { pickup, yandex }

/// Переключатель способа получения заказа. Пока функционально доступен
/// только самовывоз — «Яндекс.Доставка» уже показывается (см. PLAN.md → М5),
/// чтобы покупатель знал, что доставка скоро появится, но при выборе ведёт
/// на заглушку (см. YandexDeliveryComingSoon), не на реальное оформление.
class DeliveryMethodSelector extends StatelessWidget {
  final DeliveryMethod value;
  final ValueChanged<DeliveryMethod> onChanged;

  const DeliveryMethodSelector({
    super.key,
    required this.value,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      children: [
        Expanded(
          child: _Segment(
            label: 'Самовывоз',
            icon: Icons.storefront_rounded,
            selected: value == DeliveryMethod.pickup,
            onTap: () => onChanged(DeliveryMethod.pickup),
            theme: theme,
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: _Segment(
            label: 'Яндекс.Доставка',
            icon: Icons.local_shipping_rounded,
            selected: value == DeliveryMethod.yandex,
            onTap: () => onChanged(DeliveryMethod.yandex),
            theme: theme,
            badge: 'скоро',
          ),
        ),
      ],
    );
  }
}

class _Segment extends StatelessWidget {
  final String label;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;
  final ThemeData theme;
  final String? badge;

  const _Segment({
    required this.label,
    required this.icon,
    required this.selected,
    required this.onTap,
    required this.theme,
    this.badge,
  });

  @override
  Widget build(BuildContext context) {
    final scheme = theme.colorScheme;

    return Material(
      color: selected ? scheme.primaryContainer : scheme.surfaceContainerHighest.withValues(alpha: 0.4),
      borderRadius: BorderRadius.circular(12),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
          child: Column(
            children: [
              Stack(
                clipBehavior: Clip.none,
                children: [
                  Icon(
                    icon,
                    size: 22,
                    color: selected ? scheme.primary : scheme.onSurfaceVariant,
                  ),
                  if (badge != null)
                    Positioned(
                      top: -6,
                      right: -18,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 5,
                          vertical: 1,
                        ),
                        decoration: BoxDecoration(
                          color: scheme.tertiary,
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          badge!,
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: scheme.onTertiary,
                            fontSize: 9,
                            height: 1.2,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                label,
                textAlign: TextAlign.center,
                style: theme.textTheme.labelMedium?.copyWith(
                  color: selected ? scheme.primary : scheme.onSurfaceVariant,
                  fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Заглушка вместо реальной доставки — честно объясняет, что фича в пути,
/// вместо того чтобы притворяться рабочей формой выбора адреса.
class YandexDeliveryComingSoon extends StatelessWidget {
  const YandexDeliveryComingSoon({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: scheme.secondaryContainer.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: scheme.secondary.withValues(alpha: 0.25)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                Icons.construction_rounded,
                size: 20,
                color: scheme.secondary,
              ),
              const SizedBox(width: 8),
              Text(
                'Доставка Яндексом в разработке',
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            'Скоро сможете выбрать адрес и увидеть точную стоимость доставки '
            'прямо здесь. Пока оформите заказ самовывозом — это займёт '
            'меньше минуты.',
            style: theme.textTheme.bodyMedium?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}
