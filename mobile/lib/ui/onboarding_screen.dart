import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../core/shop_code.dart';
import '../state/shops_state.dart';
import 'scanner_screen.dart';
import 'widgets/error_view.dart';

/// М0.4 — экран онбординга: «Найдите свой магазин».
/// Байер либо сканирует QR-код, либо вводит код магазина вручную.
///
/// Этот же экран открывается поверх каталога как «Добавить магазин»
/// (isAdditional: true) — чтобы не писать второй почти такой же экран.
class OnboardingScreen extends StatefulWidget {
  final bool isAdditional;

  const OnboardingScreen({super.key, this.isAdditional = false});

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final _controller = TextEditingController();
  AppException? _error;
  bool _submitting = false;

  // Коды моковых магазинов — только для отладки на эмуляторе, где
  // камера бесполезна. В релизной сборке этого блока не будет.
  static const _debugCodes = ['FRUIT7', 'COFFEE1', 'TECH99'];

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  bool get _canSubmit => parseShopCode(_controller.text) != null && !_submitting;

  Future<void> _submit(String raw) async {
    if (parseShopCode(raw) == null) return;
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      await context.read<ShopsState>().addOrSwitch(raw);
      if (mounted && widget.isAdditional) Navigator.of(context).pop();
    } on AppException catch (e) {
      setState(() => _error = e);
    } catch (_) {
      setState(() => _error = AppException.unknown());
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _openScanner() async {
    final result = await Navigator.of(context).push<String>(
      MaterialPageRoute(builder: (_) => const ScannerScreen()),
    );
    if (result != null && mounted) {
      _controller.text = parseShopCode(result) ?? result;
      await _submit(result);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: widget.isAdditional
          ? AppBar(title: const Text('Добавить магазин'))
          : null,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (!widget.isAdditional) ...[
                    Icon(
                      Icons.storefront_rounded,
                      size: 64,
                      color: Theme.of(context).colorScheme.primary,
                    ),
                    const SizedBox(height: 16),
                    Text(
                      'Найдите свой магазин',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.headlineSmall,
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Отсканируйте QR-код магазина или введите код вручную',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: Theme.of(context).colorScheme.onSurfaceVariant,
                          ),
                    ),
                    const SizedBox(height: 32),
                  ],
                  FilledButton.icon(
                    onPressed: _submitting ? null : _openScanner,
                    icon: const Icon(Icons.qr_code_scanner_rounded),
                    label: const Text('Сканировать QR-код'),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      const Expanded(child: Divider()),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        child: Text(
                          'или',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ),
                      const Expanded(child: Divider()),
                    ],
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _controller,
                    textCapitalization: TextCapitalization.characters,
                    textInputAction: TextInputAction.done,
                    inputFormatters: [ShopCodeFormatter()],
                    decoration: const InputDecoration(
                      labelText: 'Код магазина',
                      hintText: 'Например, FRUIT7',
                    ),
                    onChanged: (_) => setState(() {}),
                    onSubmitted: _canSubmit ? _submit : null,
                  ),
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: _canSubmit ? () => _submit(_controller.text) : null,
                    child: _submitting
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('Продолжить'),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 20),
                    ErrorView(error: _error!),
                  ],
                  if (kDebugMode) ...[
                    const SizedBox(height: 24),
                    Text(
                      'Отладка: тестовые магазины',
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      alignment: WrapAlignment.center,
                      children: _debugCodes
                          .map(
                            (code) => ActionChip(
                              label: Text(code),
                              onPressed: _submitting ? null : () => _submit(code),
                            ),
                          )
                          .toList(),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
