import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

/// Плашка с адресом самовывоза — тап открывает адрес в установленном
/// у байера приложении карт (Яндекс.Карты/2GIS/Google Maps — какое есть).
///
/// geo:-ссылка — стандартный Android-способ отдать выбор карты системе,
/// а не жёстко привязываться к одному конкретному сервису.
class PickupAddressCard extends StatelessWidget {
  final String address;

  const PickupAddressCard({super.key, required this.address});

  Future<void> _open(BuildContext context) async {
    final query = Uri.encodeComponent(address);
    final geoUri = Uri.parse('geo:0,0?q=$query');

    try {
      if (await canLaunchUrl(geoUri)) {
        await launchUrl(geoUri);
        return;
      }
      // На телефоне без приложения карт — открываем веб-версию Яндекс.Карт.
      final webUri = Uri.parse('https://yandex.ru/maps/?text=$query');
      await launchUrl(webUri, mode: LaunchMode.externalApplication);
    } catch (_) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Не удалось открыть карту')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Material(
      color: theme.colorScheme.primaryContainer.withValues(alpha: 0.5),
      borderRadius: BorderRadius.circular(10),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => _open(context),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                Icons.location_on_rounded,
                size: 18,
                color: theme.colorScheme.primary,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  address,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              const SizedBox(width: 4),
              Icon(
                Icons.open_in_new_rounded,
                size: 16,
                color: theme.colorScheme.primary,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
