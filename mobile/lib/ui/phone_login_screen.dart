import 'package:flutter/material.dart';

import '../core/app_exception.dart';
import '../data/auth_repository.dart';
import 'otp_verify_screen.dart';
import 'widgets/form/phone_field.dart';
import 'widgets/primary_submit_button.dart';

/// Первый шаг входа: телефон → запрос SMS-кода → переход к вводу кода.
class PhoneLoginScreen extends StatefulWidget {
  const PhoneLoginScreen({super.key});

  @override
  State<PhoneLoginScreen> createState() => _PhoneLoginScreenState();
}

class _PhoneLoginScreenState extends State<PhoneLoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneController = TextEditingController();

  bool _submitting = false;
  AppException? _error;

  @override
  void dispose() {
    _phoneController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    final phone = _phoneController.text.trim();

    try {
      final result = await AuthRepository.create().requestCode(phone);
      if (!mounted) return;
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => OtpVerifyScreen(
            phone: phone,
            maskedPhone: result.maskedPhone,
            devCode: result.devCode,
          ),
        ),
      );
    } on AppException catch (e) {
      setState(() => _error = e);
    } catch (_) {
      setState(() => _error = AppException.unknown());
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Вход')),
      body: SafeArea(
        child: Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text(
                'Войдите по телефону, чтобы сохранять адреса доставки и видеть историю заказов',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 16),
              PhoneField(
                controller: _phoneController,
                textInputAction: TextInputAction.done,
              ),
              if (_error != null) ...[
                const SizedBox(height: 12),
                Text(
                  _error!.message,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              ],
              const SizedBox(height: 20),
              PrimarySubmitButton(
                label: 'Получить код',
                loading: _submitting,
                onPressed: _submit,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
