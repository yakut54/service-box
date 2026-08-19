import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../data/checkout_draft_store.dart';
import '../data/order_repository.dart';
import '../state/cart_state.dart';
import '../state/shop_state.dart';
import 'order_confirmation_screen.dart';
import 'widgets/form/email_field.dart';
import 'widgets/form/name_field.dart';
import 'widgets/form/phone_field.dart';
import 'widgets/form/primary_submit_button.dart';
import 'widgets/pickup_address_card.dart';

/// Оформление заказа. MVP — только самовывоз, без выбора доставки
/// (см. PLAN.md, Шаг E). Телефон не подтверждается кодом — это не
/// требуется бэкендом для создания заказа, только для истории заказов.
///
/// Черновик формы сохраняется на каждое изменение и восстанавливается
/// при повторном открытии — как в веб-виджете (см. CheckoutDraftStore).
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key});

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _phoneController = TextEditingController();
  final _emailController = TextEditingController();
  final _draftStore = CheckoutDraftStore();

  bool _submitting = false;
  AppException? _error;

  @override
  void initState() {
    super.initState();
    _restoreDraft();
    for (final controller in [
      _nameController,
      _phoneController,
      _emailController,
    ]) {
      controller.addListener(_saveDraft);
    }
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _emailController.dispose();
    super.dispose();
  }

  Future<void> _restoreDraft() async {
    final draft = await _draftStore.load();
    if (draft == null || !mounted) return;
    _nameController.text = draft['name'] ?? '';
    _phoneController.text = draft['phone'] ?? '';
    _emailController.text = draft['email'] ?? '';
  }

  void _saveDraft() {
    _draftStore.save(
      name: _nameController.text,
      phone: _phoneController.text,
      email: _emailController.text,
    );
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final cart = context.read<CartState>();
      final order = await OrderRepository.create().createOrder(
        name: _nameController.text.trim(),
        email: _emailController.text.trim(),
        phone: _phoneController.text.trim(),
        items: cart.items,
      );
      cart.clear();
      await _draftStore.clear();
      if (!mounted) return;
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => OrderConfirmationScreen(order: order),
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
    final cart = context.watch<CartState>();
    final pickupAddress = context.watch<ShopState>().shop?.pickupAddress;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Оформление заказа')),
      body: SafeArea(
        child: Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text('Контактные данные', style: theme.textTheme.titleMedium),
              const SizedBox(height: 12),
              NameField(controller: _nameController),
              const SizedBox(height: 12),
              PhoneField(controller: _phoneController),
              const SizedBox(height: 12),
              EmailField(controller: _emailController),
              const SizedBox(height: 24),
              Text('Заказ', style: theme.textTheme.titleMedium),
              const SizedBox(height: 8),
              Text(
                '${cart.itemCount} шт. на сумму ${formatRubles(cart.totalRubles)}',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                'Самовывоз',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              if (pickupAddress != null) ...[
                const SizedBox(height: 10),
                PickupAddressCard(address: pickupAddress),
              ],
              if (_error != null) ...[
                const SizedBox(height: 16),
                Text(
                  _error!.message,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
              ],
              const SizedBox(height: 24),
              PrimarySubmitButton(
                label: 'Оформить заказ',
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
