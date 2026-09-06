import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../data/profile_repository.dart';
import '../models/profile.dart';
import '../services/notification_permission.dart';
import '../state/auth_state.dart';
import 'widgets/error_view.dart';

/// Центр настроек push-уведомлений байера. Три строки:
///   • «О заказах и сообщениях» — транзакционные, всегда включены, недоступны;
///   • «Полезные напоминания» — поведенческие (tier 2), по умолчанию включены;
///   • «Акции и новости магазина» — кампании (tier 3), по умолчанию выключены.
/// Значения хранятся на сервере (customers.notification_prefs), гейт — в Notifier.
class NotificationSettingsScreen extends StatefulWidget {
  const NotificationSettingsScreen({super.key});

  @override
  State<NotificationSettingsScreen> createState() =>
      _NotificationSettingsScreenState();
}

class _NotificationSettingsScreenState
    extends State<NotificationSettingsScreen> {
  final _repo = ProfileRepository.create();

  NotificationPrefs? _prefs;
  bool _loading = true;
  AppException? _error;
  bool _systemBlocked = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  String? get _token => context.read<AuthState>().session?.sessionToken;

  Future<void> _load() async {
    final token = _token;
    if (token == null) return;

    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final prefs = await _repo.fetchNotificationPrefs(token);
      final blocked = await NotificationPermission.shouldOfferEnableInProfile();
      if (!mounted) return;
      setState(() {
        _prefs = prefs;
        _systemBlocked = blocked;
      });
    } on AppException catch (e) {
      setState(() => _error = e);
    } catch (_) {
      setState(() => _error = AppException.unknown());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  /// Переключатель отзывается сразу (оптимистично), запрос уходит в фон.
  /// Другие тумблеры не блокируем — каждый сохраняется сам по себе; при
  /// ошибке откатывается только тот, что не сохранился.
  Future<void> _toggle(bool campaign, bool value) async {
    final token = _token;
    final current = _prefs;
    if (token == null || current == null) return;

    final next = campaign
        ? current.copyWith(campaign: value)
        : current.copyWith(behavioral: value);
    setState(() => _prefs = next);

    try {
      await _repo.updateNotificationPrefs(token, next);
    } catch (_) {
      if (!mounted) return;
      final now = _prefs!;
      setState(() => _prefs = campaign
          ? now.copyWith(campaign: !value)
          : now.copyWith(behavioral: !value));
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(
          const SnackBar(content: Text('Не удалось сохранить, попробуйте ещё раз')),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Уведомления')),
      body: SafeArea(child: _buildBody(context)),
    );
  }

  Widget _buildBody(BuildContext context) {
    if (_loading && _prefs == null) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _prefs == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: ErrorView(error: _error!, onRetry: _load),
        ),
      );
    }

    final theme = Theme.of(context);
    final prefs = _prefs!;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_systemBlocked)
          Card(
            color: theme.colorScheme.errorContainer,
            child: ListTile(
              leading: Icon(
                Icons.notifications_off_outlined,
                color: theme.colorScheme.onErrorContainer,
              ),
              title: Text(
                'Уведомления выключены в настройках телефона',
                style: TextStyle(color: theme.colorScheme.onErrorContainer),
              ),
              subtitle: Text(
                'Пока не включите — ничего не придёт, даже о заказах.',
                style: TextStyle(color: theme.colorScheme.onErrorContainer),
              ),
              trailing: Icon(
                Icons.chevron_right_rounded,
                color: theme.colorScheme.onErrorContainer,
              ),
              onTap: NotificationPermission.openSystemSettings,
            ),
          ),
        const SizedBox(height: 8),
        // Транзакционные не отключаются — показываем как справку, без тумблера:
        // «мёртвый» серый переключатель читался бы как «сломалось». Замочек
        // справа сообщает, что строка не кликабельна намеренно.
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.receipt_long_outlined),
          title: const Text('О заказах и сообщениях'),
          subtitle: const Text(
            'Статус заказа, доплата, ответы магазина — приходят всегда.',
          ),
          trailing: Icon(
            Icons.lock_outline_rounded,
            size: 18,
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const Divider(height: 8),
        SwitchListTile(
          value: prefs.behavioral,
          onChanged: (v) => _toggle(false, v),
          contentPadding: EdgeInsets.zero,
          secondary: const Icon(Icons.lightbulb_outline_rounded),
          title: const Text('Полезные напоминания'),
          subtitle: const Text(
            'Забытая корзина, товар снова в наличии, персональные предложения.',
          ),
        ),
        SwitchListTile(
          value: prefs.campaign,
          onChanged: (v) => _toggle(true, v),
          contentPadding: EdgeInsets.zero,
          secondary: const Icon(Icons.local_offer_outlined),
          title: const Text('Акции и новости магазина'),
          subtitle: const Text('Скидки, распродажи, новинки. Не чаще нескольких раз в неделю.'),
        ),
      ],
    );
  }
}
