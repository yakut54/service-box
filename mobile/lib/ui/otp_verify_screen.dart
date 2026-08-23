import 'dart:async';

import 'package:flutter/material.dart';
import 'package:pinput/pinput.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../data/auth_repository.dart';
import '../state/auth_state.dart';
import 'widgets/success_flash.dart';

/// Второй шаг входа: ввод 4-значного кода из SMS. Автосабмит при вводе
/// последней цифры, таймер повторной отправки — 30 сек (совпадает с лимитом
/// запросов кода на бэкенде: 3 запроса / 5 минут).
class OtpVerifyScreen extends StatefulWidget {
  final String phone;
  final String? maskedPhone;

  /// Код из ответа сервера, пока нет реальной отправки SMS — показывается
  /// подсказкой под полем ввода до отдельного распоряжения (см.
  /// WidgetPhoneVerificationController). Когда подключат SMS, бэкенд
  /// перестанет присылать это поле, и подсказка сама пропадёт без правок кода.
  final String? devCode;

  const OtpVerifyScreen({
    super.key,
    required this.phone,
    this.maskedPhone,
    this.devCode,
  });

  @override
  State<OtpVerifyScreen> createState() => _OtpVerifyScreenState();
}

class _OtpVerifyScreenState extends State<OtpVerifyScreen> {
  static const _resendCooldown = 30;

  final _pinController = TextEditingController();
  Timer? _timer;
  int _secondsLeft = _resendCooldown;

  bool _verifying = false;
  bool _resending = false;
  AppException? _error;
  late String? _devCode = widget.devCode;

  @override
  void initState() {
    super.initState();
    _startCooldown();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _pinController.dispose();
    super.dispose();
  }

  void _startCooldown() {
    _secondsLeft = _resendCooldown;
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (_secondsLeft <= 1) {
        timer.cancel();
        setState(() => _secondsLeft = 0);
      } else {
        setState(() => _secondsLeft--);
      }
    });
  }

  Future<void> _resend() async {
    setState(() {
      _resending = true;
      _error = null;
    });
    try {
      final result = await AuthRepository.create().requestCode(widget.phone);
      if (mounted) setState(() => _devCode = result.devCode);
      _startCooldown();
    } on AppException catch (e) {
      setState(() => _error = e);
    } catch (_) {
      setState(() => _error = AppException.unknown());
    } finally {
      if (mounted) setState(() => _resending = false);
    }
  }

  Future<void> _verify(String code) async {
    setState(() {
      _verifying = true;
      _error = null;
    });

    try {
      final session = await AuthRepository.create().verifyCode(
        widget.phone,
        code,
      );
      if (!mounted) return;
      await context.read<AuthState>().setSession(session);
      if (!mounted) return;
      setState(() => _verifying = false);
      await showSuccessFlash(context, message: 'Вход выполнен');
      if (!mounted) return;
      Navigator.of(context).popUntil((route) => route.isFirst);
    } on AppException catch (e) {
      setState(() => _error = e);
      _pinController.clear();
    } catch (_) {
      setState(() => _error = AppException.unknown());
      _pinController.clear();
    } finally {
      if (mounted) setState(() => _verifying = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final defaultPinTheme = PinTheme(
      width: 56,
      height: 56,
      textStyle: theme.textTheme.headlineSmall,
      decoration: BoxDecoration(
        border: Border.all(color: theme.colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(12),
      ),
    );

    return Scaffold(
      appBar: AppBar(title: const Text('Введите код')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                widget.maskedPhone != null
                    ? 'Код отправлен на ${widget.maskedPhone}'
                    : 'Код отправлен на ваш телефон',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 20),
              Center(
                child: Pinput(
                  length: 4,
                  controller: _pinController,
                  enabled: !_verifying,
                  defaultPinTheme: defaultPinTheme,
                  focusedPinTheme: defaultPinTheme.copyDecorationWith(
                    border: Border.all(
                      color: theme.colorScheme.primary,
                      width: 2,
                    ),
                  ),
                  onCompleted: _verify,
                ),
              ),
              // Пока нет реальной отправки SMS — сервер присылает код прямо
              // в ответе (_dev_code), показываем его тут же, чтобы можно было
              // войти без SMS. Как только подключат SMS, бэкенд перестанет
              // присылать это поле, и подсказка пропадёт сама.
              if (_devCode != null) ...[
                const SizedBox(height: 12),
                Center(
                  child: Text(
                    'Код (пока без SMS): $_devCode',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.tertiary,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ],
              if (_verifying) ...[
                const SizedBox(height: 16),
                const Center(
                  child: SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                ),
              ],
              if (_error != null) ...[
                const SizedBox(height: 16),
                Text(
                  _error!.message,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              ],
              const SizedBox(height: 24),
              Center(
                child: _secondsLeft > 0
                    ? Text(
                        'Повторить через $_secondsLeft с',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      )
                    : TextButton(
                        onPressed: _resending ? null : _resend,
                        child: Text(
                          _resending ? 'Отправка...' : 'Отправить код ещё раз',
                        ),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
