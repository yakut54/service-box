import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/app_exception.dart';
import '../data/address_repository.dart';
import '../models/address.dart';
import '../state/auth_state.dart';
import 'add_address_screen.dart';
import 'widgets/error_view.dart';

/// Список сохранённых адресов: свайп удаляет, тап по не-дефолтному адресу
/// делает его адресом по умолчанию.
class AddressesScreen extends StatefulWidget {
  const AddressesScreen({super.key});

  @override
  State<AddressesScreen> createState() => _AddressesScreenState();
}

class _AddressesScreenState extends State<AddressesScreen> {
  List<Address> _addresses = [];
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
      _addresses = await AddressRepository.create().list(token);
    } on AppException catch (e) {
      setState(() => _error = e);
    } catch (_) {
      setState(() => _error = AppException.unknown());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _setDefault(Address address) async {
    final token = _sessionToken;
    if (token == null || address.isDefault) return;
    await AddressRepository.create().setDefault(token, address.id);
    _load();
  }

  Future<void> _delete(Address address) async {
    final token = _sessionToken;
    if (token == null) return;
    setState(() => _addresses.removeWhere((a) => a.id == address.id));
    await AddressRepository.create().delete(token, address.id);
    _load();
  }

  Future<void> _openAddScreen() async {
    final added = await Navigator.of(
      context,
    ).push<bool>(MaterialPageRoute(builder: (_) => const AddAddressScreen()));
    if (added == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Мои адреса')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openAddScreen,
        icon: const Icon(Icons.add_rounded),
        label: const Text('Добавить'),
      ),
      body: SafeArea(child: _buildBody(context)),
    );
  }

  Widget _buildBody(BuildContext context) {
    if (_loading && _addresses.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _addresses.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: ErrorView(error: _error!, onRetry: _load),
        ),
      );
    }

    if (_addresses.isEmpty) {
      final theme = Theme.of(context);
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.location_off_outlined,
                size: 48,
                color: theme.colorScheme.onSurfaceVariant.withValues(
                  alpha: 0.5,
                ),
              ),
              const SizedBox(height: 12),
              Text('Адресов пока нет', style: theme.textTheme.titleMedium),
            ],
          ),
        ),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 80),
      itemCount: _addresses.length,
      separatorBuilder: (_, _) => const SizedBox(height: 8),
      itemBuilder: (context, index) => _AddressTile(
        address: _addresses[index],
        onTap: () => _setDefault(_addresses[index]),
        onDelete: () => _delete(_addresses[index]),
      ),
    );
  }
}

class _AddressTile extends StatelessWidget {
  final Address address;
  final VoidCallback onTap;
  final VoidCallback onDelete;

  const _AddressTile({
    required this.address,
    required this.onTap,
    required this.onDelete,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Dismissible(
      key: ValueKey(address.id),
      direction: DismissDirection.endToStart,
      onDismissed: (_) => onDelete(),
      background: Container(
        alignment: Alignment.centerRight,
        padding: const EdgeInsets.symmetric(horizontal: 20),
        decoration: BoxDecoration(
          color: theme.colorScheme.errorContainer,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Icon(
          Icons.delete_outline_rounded,
          color: theme.colorScheme.onErrorContainer,
        ),
      ),
      child: Card(
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(12),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Row(
              children: [
                Icon(
                  address.isDefault
                      ? Icons.check_circle_rounded
                      : Icons.location_on_outlined,
                  color: address.isDefault
                      ? theme.colorScheme.primary
                      : theme.colorScheme.onSurfaceVariant,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (address.label != null && address.label!.isNotEmpty)
                        Text(
                          address.label!,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      Text(
                        address.fullLine,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
