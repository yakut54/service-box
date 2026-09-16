import 'package:flutter/material.dart';

/// Серый прямоугольник-заглушка для skeleton-загрузки. Цвет —
/// `surfaceContainerHighest`, тот же, что уже используют плейсхолдеры
/// картинок в проекте (product_card.dart, related_products.dart,
/// product_detail_screen.dart, chat_screen.dart) — ничего нового не вводим.
class SkeletonBox extends StatelessWidget {
  final double? width;
  final double height;
  final BorderRadius borderRadius;

  const SkeletonBox({
    super.key,
    this.width,
    required this.height,
    this.borderRadius = const BorderRadius.all(Radius.circular(6)),
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surfaceContainerHighest,
        borderRadius: borderRadius,
      ),
    );
  }
}

/// Круглая заглушка — для аватарок.
class SkeletonCircle extends StatelessWidget {
  final double size;

  const SkeletonCircle({super.key, required this.size});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surfaceContainerHighest,
        shape: BoxShape.circle,
      ),
    );
  }
}

/// Бегущая подсветка поверх любой skeleton-раскладки — оборачивает ЦЕЛИКОМ
/// композицию из [SkeletonBox]/[SkeletonCircle] одной анимацией, не по
/// одной на каждый прямоугольник. Свой AnimationController, без пакетов —
/// тот же приём, что уже есть в проекте у aurora_background.dart.
class Shimmer extends StatefulWidget {
  final Widget child;

  const Shimmer({super.key, required this.child});

  @override
  State<Shimmer> createState() => _ShimmerState();
}

class _ShimmerState extends State<Shimmer> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1200),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final base = Theme.of(context).colorScheme.surfaceContainerHighest;
    final highlight = Color.lerp(base, Colors.white, 0.35)!;

    return AnimatedBuilder(
      animation: _controller,
      builder: (context, child) {
        // t пробегает -1..2, чтобы полоса подсветки полностью проходила
        // экран слева направо и уходила за край перед тем как начать сначала.
        final t = -1 + _controller.value * 3;
        return ShaderMask(
          blendMode: BlendMode.srcATop,
          shaderCallback: (bounds) => LinearGradient(
            colors: [base, highlight, base],
            stops: const [0.35, 0.5, 0.65],
            begin: Alignment(t - 1, 0),
            end: Alignment(t + 1, 0),
          ).createShader(bounds),
          child: child,
        );
      },
      child: widget.child,
    );
  }
}
