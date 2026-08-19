import 'package:flutter/material.dart';
import 'package:flutter_native_splash/flutter_native_splash.dart';

import 'widgets/aurora_background.dart';

/// Анимированное интро при запуске (~4с) — играет поверх переливающегося
/// фона (AuroraBackground), пока в фоне грузится магазин (см. _BootScreen
/// в main.dart). Фантик «разворачивается», открывая конфету, которая мягко
/// проявляется по центру.
///
/// Нативный сплэш Android (drawable/launch_background, до старта Flutter)
/// сложную анимацию физически не поддерживает — это ограничение самой ОС,
/// не наше решение (см. README.md → «Флейворы»). Поэтому весь «вау-эффект»
/// живёт здесь, первым кадром Flutter, а не в системном сплэше.
class SplashIntroScreen extends StatefulWidget {
  const SplashIntroScreen({super.key});

  @override
  State<SplashIntroScreen> createState() => _SplashIntroScreenState();
}

class _SplashIntroScreenState extends State<SplashIntroScreen>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 4000),
  );

  @override
  void initState() {
    super.initState();
    // Первый кадр (t=0 — фантик и искры ещё не видны, только фон) рисуем
    // ДО того, как убираем нативный сплэш — иначе между ними мелькнёт пустой
    // кадр. Стартуем анимацию в тот же момент, синхронно с исчезновением
    // сплэша, а не когда Android сам решит его убрать.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      FlutterNativeSplash.remove();
      _controller.forward();
    });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: AuroraBackground(
        child: Center(
          child: AnimatedBuilder(
            animation: _controller,
            builder: (context, _) {
              final t = _controller.value;
              return SizedBox(
                width: 260,
                height: 260,
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    _CandyIcon(t: t),
                    _WrapperPetal(t: t, fromLeft: true),
                    _WrapperPetal(t: t, fromLeft: false),
                    _Sparkles(t: t),
                  ],
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}

/// Конфета проявляется, пока фантик разлетается (0.5 → 0.95), с лёгким
/// пружинным нахлёстом (easeOutBack — чуть перескакивает и оседает).
class _CandyIcon extends StatelessWidget {
  final double t;

  const _CandyIcon({required this.t});

  @override
  Widget build(BuildContext context) {
    final raw = ((t - 0.5) / 0.45).clamp(0.0, 1.0);
    final reveal = Curves.easeOutBack.transform(raw);

    return Opacity(
      opacity: reveal.clamp(0.0, 1.0),
      child: Transform.scale(
        scale: 0.55 + 0.45 * reveal,
        child: Image.asset(
          'assets/icon/barbariska_app_icon.png',
          width: 160,
          height: 160,
        ),
      ),
    );
  }
}

/// Один «хвостик» фантика — залетает и закручивается поверх конфеты,
/// держится долю секунды, затем раскручивается и улетает за край экрана.
class _WrapperPetal extends StatelessWidget {
  final double t;
  final bool fromLeft;

  const _WrapperPetal({required this.t, required this.fromLeft});

  @override
  Widget build(BuildContext context) {
    final sign = fromLeft ? -1.0 : 1.0;

    double progressIn;
    double progressOut;
    double opacity;

    if (t < 0.35) {
      progressIn = Curves.easeOutCubic.transform((t / 0.35).clamp(0.0, 1.0));
      progressOut = 0;
      opacity = 1;
    } else if (t < 0.45) {
      progressIn = 1;
      progressOut = 0;
      opacity = 1;
    } else {
      progressIn = 1;
      progressOut = Curves.easeInCubic.transform(
        ((t - 0.45) / 0.4).clamp(0.0, 1.0),
      );
      opacity = (1 - progressOut).clamp(0.0, 1.0);
    }

    final dx = sign * 220 * (1 - progressIn) + sign * 260 * progressOut;
    final rotation = sign * (progressIn * 0.9 + progressOut * 1.4);

    return Opacity(
      opacity: opacity,
      child: Transform.translate(
        offset: Offset(dx, 0),
        child: Transform.rotate(
          angle: rotation,
          child: ClipPath(
            clipper: _PetalClipper(),
            child: Container(
              width: 130,
              height: 190,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    Colors.white.withValues(alpha: 0.92),
                    const Color(0xFFFFE3A3).withValues(alpha: 0.85),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Форма «хвостика фантика» — вытянутый скрученный ромб, а не просто
/// прямоугольник, чтобы читалось как обёртка, а не как абстрактная плашка.
class _PetalClipper extends CustomClipper<Path> {
  @override
  Path getClip(Size size) {
    final w = size.width, h = size.height;
    return Path()
      ..moveTo(w * 0.5, 0)
      ..quadraticBezierTo(w, h * 0.25, w * 0.5, h * 0.5)
      ..quadraticBezierTo(0, h * 0.75, w * 0.5, h)
      ..quadraticBezierTo(w * 0.85, h * 0.5, w * 0.5, 0)
      ..close();
  }

  @override
  bool shouldReclip(covariant CustomClipper<Path> oldClipper) => false;
}

/// Блёстки-искры вокруг конфеты в момент раскрытия (0.55 → 0.95) — быстро
/// проявляются и гаснут, слегка разлетаясь от центра.
class _Sparkles extends StatelessWidget {
  final double t;

  const _Sparkles({required this.t});

  static const List<Offset> _positions = [
    Offset(-70, -60),
    Offset(80, -40),
    Offset(-50, 70),
    Offset(75, 65),
    Offset(0, -95),
  ];

  @override
  Widget build(BuildContext context) {
    if (t < 0.55) return const SizedBox.shrink();

    final local = ((t - 0.55) / 0.4).clamp(0.0, 1.0);
    final opacity = local < 0.6 ? local / 0.6 : (1 - (local - 0.6) / 0.4);

    return Stack(
      children: [
        for (final p in _positions)
          Transform.translate(
            offset: p * (0.7 + 0.3 * local),
            child: Opacity(
              opacity: opacity.clamp(0.0, 1.0),
              child: const Icon(
                Icons.auto_awesome_rounded,
                color: Colors.white,
                size: 18,
              ),
            ),
          ),
      ],
    );
  }
}
