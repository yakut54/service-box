import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../core/app_exception.dart';
import '../core/shop_code.dart';
import 'widgets/error_view.dart';

/// М0.4 — экран сканера. Отдаёт наверх (через Navigator.pop) сырую строку
/// из QR-кода и ничего больше не делает: разбор кода и поиск магазина —
/// забота онбординга, чтобы ошибки обрабатывались в одном месте.
class ScannerScreen extends StatefulWidget {
  const ScannerScreen({super.key});

  @override
  State<ScannerScreen> createState() => _ScannerScreenState();
}

class _ScannerScreenState extends State<ScannerScreen> {
  final _controller = MobileScannerController(
    formats: const [BarcodeFormat.qrCode],
    detectionSpeed: DetectionSpeed.noDuplicates,
  );

  // Камера присылает один и тот же QR-код десятки раз в секунду —
  // без этого флага получим кучу параллельных обработок одного скана.
  bool _handled = false;
  String? _hint;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_handled) return;
    final raw = capture.barcodes.isNotEmpty ? capture.barcodes.first.rawValue : null;
    if (raw == null) return;

    if (parseShopCode(raw) == null) {
      if (mounted) setState(() => _hint = 'Это не похоже на QR-код магазина');
      return;
    }

    _handled = true;
    await _controller.stop();
    if (mounted) Navigator.of(context).pop(raw);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: const Text('Сканирование QR-кода'),
        actions: [
          IconButton(
            icon: const Icon(Icons.flash_on_rounded),
            tooltip: 'Фонарик',
            onPressed: () => _controller.toggleTorch(),
          ),
        ],
      ),
      body: Stack(
        fit: StackFit.expand,
        children: [
          MobileScanner(
            controller: _controller,
            onDetect: _onDetect,
            errorBuilder: (context, error) => ErrorView(
              error: _mapScannerError(error),
              secondaryLabel: 'Ввести код вручную',
              onSecondary: () => Navigator.of(context).pop(),
            ),
          ),
          IgnorePointer(
            child: Center(
              child: Container(
                width: 260,
                height: 260,
                decoration: BoxDecoration(
                  border: Border.all(color: Colors.white, width: 2),
                  borderRadius: BorderRadius.circular(16),
                ),
              ),
            ),
          ),
          Positioned(
            left: 0,
            right: 0,
            bottom: 96,
            child: Text(
              _hint ?? 'Наведите камеру на QR-код магазина',
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white),
            ),
          ),
          Positioned(
            left: 0,
            right: 0,
            bottom: 32,
            child: Center(
              child: TextButton(
                style: TextButton.styleFrom(foregroundColor: Colors.white),
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Ввести код вручную'),
              ),
            ),
          ),
        ],
      ),
    );
  }

  AppException _mapScannerError(MobileScannerException error) {
    switch (error.errorCode) {
      case MobileScannerErrorCode.permissionDenied:
        return AppException.cameraPermission();
      case MobileScannerErrorCode.unsupported:
        return AppException.cameraUnavailable();
      default:
        return AppException.cameraUnavailable();
    }
  }
}
