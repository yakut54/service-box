import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../core/format.dart';
import '../data/profile_repository.dart';
import '../models/profile.dart';
import '../state/auth_state.dart';
import 'addresses_screen.dart';
import 'orders_screen.dart';
import 'widgets/error_view.dart';
import 'widgets/form/name_field.dart';
import 'widgets/form/primary_submit_button.dart';
import 'widgets/success_flash.dart';

class AccountScreen extends StatefulWidget {
  const AccountScreen({super.key});

  @override
  State<AccountScreen> createState() => _AccountScreenState();
}

class _AccountScreenState extends State<AccountScreen> {
  Profile? _profile;
  bool _loading = true;
  AppException? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  String? get _sessionToken => context.read<AuthState>().session?.sessionToken;

  Future<void> _load() async {
    final token = _sessionToken;
    if (token == null) return;

    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      _profile = await ProfileRepository.create().fetch(token);
    } on AppException catch (e) {
      setState(() => _error = e);
    } catch (_) {
      setState(() => _error = AppException.unknown());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _editName() async {
    final token = _sessionToken;
    final profile = _profile;
    if (token == null || profile == null) return;

    final updated = await showDialog<Profile>(
      context: context,
      builder: (_) => _EditNameDialog(sessionToken: token, initialName: profile.name),
    );

    if (updated != null && mounted) {
      setState(() => _profile = updated);
      showSuccessFlash(context, message: 'Имя сохранено');
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();

    return Scaffold(
      appBar: AppBar(title: const Text('Профиль')),
      body: SafeArea(child: _buildBody(context, auth)),
    );
  }

  Widget _buildBody(BuildContext context, AuthState auth) {
    if (_loading && _profile == null) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _profile == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: ErrorView(error: _error!, onRetry: _load),
        ),
      );
    }

    final theme = Theme.of(context);
    final profile = _profile;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Row(
          children: [
            CircleAvatar(
              radius: 24,
              backgroundColor: theme.colorScheme.primaryContainer,
              child: Icon(
                Icons.person_rounded,
                color: theme.colorScheme.primary,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    (profile?.name.isNotEmpty ?? false)
                        ? profile!.name
                        : 'Без имени',
                    style: theme.textTheme.titleMedium,
                  ),
                  Text(
                    auth.session?.phone ?? profile?.phone ?? '',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ),
            IconButton(
              icon: const Icon(Icons.edit_outlined),
              onPressed: profile == null ? null : _editName,
              tooltip: 'Изменить имя',
            ),
          ],
        ),
        if (profile != null) ...[
          const SizedBox(height: 20),
          _StatsRow(profile: profile),
        ],
        const SizedBox(height: 24),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.receipt_long_outlined),
          title: const Text('Мои заказы'),
          trailing: const Icon(Icons.chevron_right_rounded),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const OrdersScreen()),
          ),
        ),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.location_on_outlined),
          title: const Text('Мои адреса'),
          trailing: const Icon(Icons.chevron_right_rounded),
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const AddressesScreen()),
          ),
        ),
        const SizedBox(height: 12),
        OutlinedButton(
          onPressed: () async {
            await context.read<AuthState>().logout();
            if (context.mounted) Navigator.of(context).pop();
          },
          child: const Text('Выйти'),
        ),
      ],
    );
  }
}

class _StatsRow extends StatelessWidget {
  final Profile profile;

  const _StatsRow({required this.profile});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: _StatTile(
            label: 'Заказов',
            value: '${profile.totalOrders}',
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: _StatTile(
            label: 'Потрачено',
            value: formatRubles(profile.totalSpentRubles),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: _StatTile(
            label: 'Бонусы',
            value: '${profile.bonusBalance}',
          ),
        ),
      ],
    );
  }
}

class _StatTile extends StatelessWidget {
  final String label;
  final String value;

  const _StatTile({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 8),
        child: Column(
          children: [
            Text(
              value,
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w700,
              ),
              textAlign: TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
            const SizedBox(height: 2),
            Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _EditNameDialog extends StatefulWidget {
  final String sessionToken;
  final String initialName;

  const _EditNameDialog({required this.sessionToken, required this.initialName});

  @override
  State<_EditNameDialog> createState() => _EditNameDialogState();
}

class _EditNameDialogState extends State<_EditNameDialog> {
  final _formKey = GlobalKey<FormState>();
  late final _controller = TextEditingController(text: widget.initialName);
  bool _submitting = false;
  AppException? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final updated = await ProfileRepository.create().updateName(
        widget.sessionToken,
        _controller.text.trim(),
      );
      if (!mounted) return;
      Navigator.of(context).pop(updated);
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

    return AlertDialog(
      title: const Text('Изменить имя'),
      content: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            NameField(
              controller: _controller,
              textInputAction: TextInputAction.done,
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(
                _error!.message,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ],
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: _submitting ? null : () => Navigator.of(context).pop(),
          child: const Text('Отмена'),
        ),
        SizedBox(
          width: 120,
          child: PrimarySubmitButton(
            label: 'Сохранить',
            loading: _submitting,
            onPressed: _submit,
          ),
        ),
      ],
    );
  }
}
