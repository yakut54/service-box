import 'package:flutter/material.dart';

/// Звёзды 1-5 — на просмотр (в карточке товара, в списке отзывов) и на
/// выбор (в форме отправки отзыва), один виджет на оба случая, чтобы не
/// дублировать отрисовку звёзд в двух местах.
///
/// Без ховер-превью, как в веб-версии — на тач-экране наводить курсор
/// нечем, тап сразу ставит оценку.
class StarRating extends StatelessWidget {
  final double value;
  final int max;
  final double size;
  final Color? color;
  final ValueChanged<int>? onRated;

  const StarRating({
    super.key,
    required this.value,
    this.max = 5,
    this.size = 18,
    this.color,
    this.onRated,
  });

  @override
  Widget build(BuildContext context) {
    final starColor = color ?? Colors.amber;
    // Без половинок звёзд — как в вебе (i <= Math.round(average)).
    final filled = value.round();

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var i = 1; i <= max; i++)
          _Star(
            index: i,
            filled: i <= filled,
            size: size,
            color: starColor,
            onTap: onRated == null ? null : () => onRated!(i),
          ),
      ],
    );
  }
}

class _Star extends StatelessWidget {
  final int index;
  final bool filled;
  final double size;
  final Color color;
  final VoidCallback? onTap;

  const _Star({
    required this.index,
    required this.filled,
    required this.size,
    required this.color,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final icon = Icon(
      filled ? Icons.star_rounded : Icons.star_outline_rounded,
      size: size,
      color: color,
    );

    if (onTap == null) return icon;

    // Минимум 44×44 область тапа — звёзды сами по себе мельче.
    return Semantics(
      button: true,
      label: '$index ${index == 1 ? 'звезда' : 'звёзд'}',
      child: GestureDetector(
        onTap: onTap,
        behavior: HitTestBehavior.opaque,
        child: SizedBox(
          width: 44,
          height: 44,
          child: Center(child: icon),
        ),
      ),
    );
  }
}
