import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../data/address_repository.dart';
import '../state/auth_state.dart';
import 'widgets/form/live_validated_field.dart';
import 'widgets/form/primary_submit_button.dart';

/// Форма добавления адреса. При успехе возвращает true через Navigator.pop,
/// чтобы AddressesScreen знал, что список нужно перезагрузить.
class AddAddressScreen extends StatefulWidget {
  const AddAddressScreen({super.key});

  @override
  State<AddAddressScreen> createState() => _AddAddressScreenState();
}

class _AddAddressScreenState extends State<AddAddressScreen> {
  final _formKey = GlobalKey<FormState>();
  final _labelController = TextEditingController();
  final _cityController = TextEditingController();
  final _streetController = TextEditingController();
  final _buildingController = TextEditingController();
  final _apartmentController = TextEditingController();
  final _postalCodeController = TextEditingController();

  bool _submitting = false;
  AppException? _error;

  /// Тикает перед `Form.validate()`, чтобы LiveValidatedField показал своё
  /// состояние сразу, даже если по нему ещё не печатали, а просто нажали
  /// «Сохранить» на пустой форме.
  final _touchedSignal = ValueNotifier<int>(0);

  @override
  void dispose() {
    _labelController.dispose();
    _cityController.dispose();
    _streetController.dispose();
    _buildingController.dispose();
    _apartmentController.dispose();
    _postalCodeController.dispose();
    _touchedSignal.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    _touchedSignal.value++;
    if (!_formKey.currentState!.validate()) return;

    final sessionToken = context.read<AuthState>().session?.sessionToken;
    if (sessionToken == null) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      await AddressRepository.create().add(
        sessionToken,
        label: _labelController.text.trim(),
        city: _cityController.text.trim(),
        street: _streetController.text.trim(),
        building: _buildingController.text.trim(),
        apartment: _apartmentController.text.trim(),
        postalCode: _postalCodeController.text.trim(),
      );
      if (!mounted) return;
      Navigator.of(context).pop(true);
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
      appBar: AppBar(title: const Text('Новый адрес')),
      body: SafeArea(
        child: Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              TextFormField(
                controller: _labelController,
                decoration: const InputDecoration(
                  labelText: 'Название (необязательно)',
                  hintText: 'Дом, Работа',
                ),
              ),
              const SizedBox(height: 12),
              LiveValidatedField(
                controller: _cityController,
                label: 'Город',
                emptyMessage: 'Укажите город',
                minLength: 2,
                shortMessage: 'Не менее 2 символов',
                touchedSignal: _touchedSignal,
              ),
              const SizedBox(height: 12),
              LiveValidatedField(
                controller: _streetController,
                label: 'Улица',
                emptyMessage: 'Укажите улицу',
                minLength: 2,
                shortMessage: 'Не менее 2 символов',
                touchedSignal: _touchedSignal,
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: LiveValidatedField(
                      controller: _buildingController,
                      label: 'Дом',
                      emptyMessage: 'Укажите дом',
                      // "5А", "12к2" — буквы в номере дома это нормально,
                      // но одна цифра быть обязана (иначе это не номер дома).
                      extraCheck: (v) => v.contains(RegExp(r'[0-9]')),
                      invalidMessage: 'Только число',
                      touchedSignal: _touchedSignal,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextFormField(
                      controller: _apartmentController,
                      decoration: const InputDecoration(labelText: 'Квартира'),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _postalCodeController,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: 'Индекс (необязательно)',
                ),
              ),
              if (_error != null) ...[
                const SizedBox(height: 16),
                Text(
                  _error!.message,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              ],
              const SizedBox(height: 20),
              PrimarySubmitButton(
                label: 'Сохранить адрес',
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
