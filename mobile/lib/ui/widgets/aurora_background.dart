import 'dart:math' as math;

import 'package:flutter/material.dart';

/// Переливающийся фон вроде северного сияния — несколько смазанных цветных
/// пятен медленно плывут и наплывают друг на друга. Чистый Flutter
/// (CustomPainter + blur), без новых пакетов и без ассетов — используется
/// на интро-экране запуска (см. splash_intro_screen.dart), заменяет плоскую
/// заливку.
class AuroraBackground extends StatefulWidget {
  final Widget? child;

  const AuroraBackground({super.key, this.child});

  @override
  State<AuroraBackground> createState() => _AuroraBackgroundState();
}

class _AuroraBackgroundState extends State<AuroraBackground>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(seconds: 8),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) => CustomPaint(
        painter: _AuroraPainter(_controller.value),
        child: widget.child,
      ),
    );
  }
}

class _AuroraPainter extends CustomPainter {
  final double t;

  _AuroraPainter(this.t);

  static const List<Color> _blobs = [
    Color(0xFFE8305A), // малиновый — в цвет конфеты
    Color(0xFFFFC93C), // золотой
    Color(0xFFFF8FA3), // коралловый
    Color(0xFFFFE3A3), // персиковый
  ];

  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawRect(
      Offset.zero & size,
      Paint()..color = const Color(0xFFFFF6E5),
    );

    for (var i = 0; i < _blobs.length; i++) {
      final angle = t * 2 * math.pi + i * (math.pi / 2);
      final dx = size.width * (0.5 + 0.4 * math.cos(angle + i));
      final dy = size.height * (0.35 + 0.3 * math.sin(angle * 1.3 + i));
      final radius = size.width * 0.6;

      final paint = Paint()
        ..shader = RadialGradient(
          colors: [
            _blobs[i].withValues(alpha: 0.5),
            _blobs[i].withValues(alpha: 0),
          ],
        ).createShader(Rect.fromCircle(center: Offset(dx, dy), radius: radius))
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 70);

      canvas.drawCircle(Offset(dx, dy), radius, paint);
    }
  }

  @override
  bool shouldRepaint(covariant _AuroraPainter oldDelegate) => oldDelegate.t != t;
}
