import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

/// Кастомный фон чата, «по мотивам» узнаваемого паттерна мессенджеров
/// (тонкие контурные значки, разбросанные по полотну) — но нарисован
/// целиком сам, без единого внешнего ассета и без копирования конкретных
/// иконок Telegram: свой набор фигур (звезда, сердце, чат-пузырь, подарок,
/// лист, пакет), свой алгоритм раскладки. Красится в цвет темы магазина
/// (`primary_color`), поэтому одинаково хорошо смотрится в любой цветовой
/// теме, а не только в зелёной.
class ChatBackground extends StatelessWidget {
  final Color color;

  const ChatBackground({super.key, required this.color});

  @override
  Widget build(BuildContext context) {
    return Positioned.fill(
      child: RepaintBoundary(
        child: CustomPaint(
          painter: _ChatBackgroundPainter(color: color),
        ),
      ),
    );
  }
}

class _ChatBackgroundPainter extends CustomPainter {
  final Color color;

  _ChatBackgroundPainter({required this.color});

  static const double _cell = 58;
  static const double _iconSize = 26;

  // Простой детерминированный хэш вместо Random — тот же узор при каждой
  // перерисовке одного и того же экрана, без побочного состояния.
  static double _pseudoRandom(int a, int b, int salt) {
    final h = (a * 374761393 + b * 668265263 + salt * 2246822519) & 0x7fffffff;
    return (h % 1000) / 1000;
  }

  @override
  void paint(Canvas canvas, Size size) {
    // Мягкий диагональный градиент — тон темы магазина на почти-белом фоне,
    // не сплошной цвет (тот же приём, что и в референсе).
    final bgPaint = Paint()
      ..shader = ui.Gradient.linear(
        Offset.zero,
        Offset(size.width, size.height),
        [
          Color.alphaBlend(color.withValues(alpha: 0.05), Colors.white),
          Color.alphaBlend(color.withValues(alpha: 0.12), Colors.white),
        ],
      );
    canvas.drawRect(Offset.zero & size, bgPaint);

    final iconPaint = Paint()
      ..color = color.withValues(alpha: 0.09)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.4
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    final rows = (size.height / _cell).ceil() + 1;
    final cols = (size.width / _cell).ceil() + 1;

    for (var row = -1; row < rows; row++) {
      final rowOffset = row.isOdd ? _cell / 2 : 0.0;
      for (var col = -1; col < cols; col++) {
        final cx = col * _cell + rowOffset + _cell / 2;
        final cy = row * _cell + _cell / 2;

        final jitterX = (_pseudoRandom(row, col, 1) - 0.5) * 14;
        final jitterY = (_pseudoRandom(row, col, 2) - 0.5) * 14;
        final rotation = _pseudoRandom(row, col, 3) * math.pi * 2;
        final scale = 0.75 + _pseudoRandom(row, col, 4) * 0.5;
        final iconIndex = (row * 7 + col * 13).abs() % _painters.length;

        canvas.save();
        canvas.translate(cx + jitterX, cy + jitterY);
        canvas.rotate(rotation);
        canvas.scale(scale);
        _painters[iconIndex](canvas, iconPaint, _iconSize);
        canvas.restore();
      }
    }
  }

  @override
  bool shouldRepaint(covariant _ChatBackgroundPainter oldDelegate) =>
      oldDelegate.color != color;
}

typedef _IconPainter = void Function(Canvas canvas, Paint paint, double size);

final List<_IconPainter> _painters = [
  _paintStar,
  _paintHeart,
  _paintBubble,
  _paintGift,
  _paintLeaf,
  _paintBag,
];

void _paintStar(Canvas canvas, Paint paint, double size) {
  final r = size / 2;
  final path = Path();
  for (var i = 0; i < 10; i++) {
    final angle = (math.pi / 5) * i - math.pi / 2;
    final radius = i.isEven ? r : r * 0.42;
    final point = Offset(math.cos(angle) * radius, math.sin(angle) * radius);
    if (i == 0) {
      path.moveTo(point.dx, point.dy);
    } else {
      path.lineTo(point.dx, point.dy);
    }
  }
  path.close();
  canvas.drawPath(path, paint);
}

void _paintHeart(Canvas canvas, Paint paint, double size) {
  final w = size, h = size;
  final path = Path()
    ..moveTo(0, h * 0.32)
    ..cubicTo(-w * 0.55, -h * 0.28, -w * 0.9, h * 0.28, 0, h * 0.55)
    ..cubicTo(w * 0.9, h * 0.28, w * 0.55, -h * 0.28, 0, h * 0.32)
    ..close();
  canvas.drawPath(path.shift(Offset(0, -h * 0.1)), paint);
}

void _paintBubble(Canvas canvas, Paint paint, double size) {
  final rect = Rect.fromCenter(
    center: Offset(0, -size * 0.05),
    width: size * 1.15,
    height: size * 0.85,
  );
  final rrect = RRect.fromRectAndRadius(rect, Radius.circular(size * 0.22));
  canvas.drawRRect(rrect, paint);
  final tail = Path()
    ..moveTo(-size * 0.15, rect.bottom - 1)
    ..lineTo(-size * 0.3, size * 0.5)
    ..lineTo(size * 0.05, rect.bottom - 1)
    ..close();
  canvas.drawPath(tail, paint);
}

void _paintGift(Canvas canvas, Paint paint, double size) {
  final box = Rect.fromCenter(center: Offset.zero, width: size * 0.9, height: size * 0.75);
  canvas.drawRect(box, paint);
  canvas.drawLine(Offset(0, box.top), Offset(0, box.bottom), paint);
  canvas.drawLine(Offset(box.left, 0), Offset(box.right, 0), paint);
  // бант сверху
  final bow = Path()
    ..moveTo(0, box.top)
    ..cubicTo(-size * 0.25, box.top - size * 0.3, -size * 0.4, box.top - size * 0.02, 0, box.top)
    ..moveTo(0, box.top)
    ..cubicTo(size * 0.25, box.top - size * 0.3, size * 0.4, box.top - size * 0.02, 0, box.top);
  canvas.drawPath(bow, paint);
}

void _paintLeaf(Canvas canvas, Paint paint, double size) {
  final path = Path()
    ..moveTo(0, -size * 0.5)
    ..quadraticBezierTo(size * 0.55, -size * 0.2, 0, size * 0.5)
    ..quadraticBezierTo(-size * 0.55, -size * 0.2, 0, -size * 0.5)
    ..close();
  canvas.drawPath(path, paint);
  canvas.drawLine(Offset(0, -size * 0.4), Offset(0, size * 0.4), paint);
}

void _paintBag(Canvas canvas, Paint paint, double size) {
  final top = -size * 0.15;
  final bottom = size * 0.5;
  final path = Path()
    ..moveTo(-size * 0.4, top)
    ..lineTo(-size * 0.48, bottom)
    ..lineTo(size * 0.48, bottom)
    ..lineTo(size * 0.4, top)
    ..close();
  canvas.drawPath(path, paint);
  final handle = Rect.fromCenter(center: Offset(0, top - size * 0.12), width: size * 0.4, height: size * 0.32);
  canvas.drawArc(handle, math.pi, math.pi, false, paint);
}
