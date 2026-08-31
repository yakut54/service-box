import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../core/phone_input_formatter.dart';
import '../data/checkout_draft_store.dart';
import '../data/order_repository.dart';
import '../data/profile_repository.dart';
import '../models/product.dart';
import '../state/auth_state.dart';
import '../state/cart_state.dart';
import '../state/shop_state.dart';
import 'order_confirmation_screen.dart';
import 'widgets/form/email_field.dart';
import 'widgets/form/name_field.dart';
import 'widgets/form/phone_field.dart';
import 'widgets/delivery_method_selector.dart';
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
  DeliveryMethod _delivery = DeliveryMethod.pickup;

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
    if (!mounted) return;
    _nameController.text = draft?['name'] ?? '';
    _phoneController.text = draft?['phone'] ?? '';
    _emailController.text = draft?['email'] ?? '';
    await _prefillFromProfile();
  }

  /// Авторизованный покупатель не должен набирать то, что мы и так знаем.
  /// Черновик формы (если есть) имеет приоритет — это его недавний ручной
  /// ввод. Тихо игнорируем ошибку сети: профиль — это только удобство,
  /// не блокер оформления заказа.
  ///
  /// waitUntilReady() — без него, если экран оформления открылся раньше, чем
  /// AuthState успел прочитать сессию из хранилища (холодный старт прямо на
  /// чекаут), сессия здесь виделась бы null и автозаполнение молча
  /// пропускалось бы для реально залогиненного покупателя — баг найден
  /// 2026-09-01, «иногда не подтягивается».
  Future<void> _prefillFromProfile() async {
    if (!mounted) return;
    final auth = context.read<AuthState>();
    await auth.waitUntilReady();
    if (!mounted) return;
    final token = auth.session?.sessionToken;
    if (token == null) return;

    try {
      final profile = await ProfileRepository.create().fetch(token);
      if (!mounted) return;
      if (_nameController.text.trim().isEmpty) {
        _nameController.text = profile.name;
      }
      if (_phoneController.text.trim().isEmpty) {
        final digits = profile.phone.replaceAll(RegExp(r'\D'), '');
        _phoneController.text = RussianPhoneInputFormatter.format(digits);
      }
      if (_emailController.text.trim().isEmpty && profile.email != null) {
        _emailController.text = profile.email!;
      }
    } catch (_) {
      // офлайн/ошибка сети — просто не автозаполнили, ничего критичного
    }
  }

  void _saveDraft() {
    _draftStore.save(
      name: _nameController.text,
      phone: _phoneController.text,
      email: _emailController.text,
    );
  }

  Future<void> _submit() async {
    if (_delivery == DeliveryMethod.yandex) return;
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final cart = context.read<CartState>();
      final hasWeightVariable = cart.items.any(
        (item) => item.product.physical?.saleMode == ProductSaleMode.weightVariable,
      );
      final order = await OrderRepository.create().createOrder(
        name: _nameController.text.trim(),
        email: _emailController.text.trim(),
        phone: _phoneController.text.trim(),
        items: cart.items,
        discountCode: cart.discount?.code,
      );
      cart.clear();
      await _draftStore.clear();

      // Заказ с товаром «по весу — перевзвешивание» — точная сумма известна
      // только после сборки, поэтому вместо обычной оплаты сразу создаём холд
      // и открываем его на оплату (см. PLAN.md, «По весу — перевзвешивание»).
      if (hasWeightVariable) {
        try {
          final paymentUrl = await OrderRepository.create().createPayment(order.id);
          final uri = Uri.parse(paymentUrl);
          if (await canLaunchUrl(uri)) {
            await launchUrl(uri, mode: LaunchMode.externalApplication);
          }
        } catch (_) {
          // Не удалось создать холд сразу — не блокируем подтверждение заказа,
          // владелец магазина увидит его в админке и разберётся вручную.
        }
      }

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
              if (cart.discount != null) ...[
                const SizedBox(height: 2),
                Text(
                  '${cart.discount!.name}: −${formatRubles(cart.discount!.amountRubles)}',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: theme.colorScheme.primary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  'К оплате: ${formatRubles(cart.totalAfterDiscountRubles)}',
                  style: theme.textTheme.titleMedium,
                ),
              ],
              const SizedBox(height: 12),
              DeliveryMethodSelector(
                value: _delivery,
                onChanged: (method) => setState(() => _delivery = method),
              ),
              const SizedBox(height: 12),
              if (_delivery == DeliveryMethod.pickup && pickupAddress != null)
                PickupAddressCard(address: pickupAddress)
              else if (_delivery == DeliveryMethod.yandex)
                const YandexDeliveryComingSoon(),
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
                onPressed: _delivery == DeliveryMethod.yandex ? null : _submit,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
