import 'package:flutter/material.dart';

import '../../core/app_exception.dart';
import '../../core/image_compress.dart';
import '../../data/profile_repository.dart';
import '../../models/profile.dart';
import 'app_dialog.dart';
import 'photo_picker_sheet.dart';

/// Аватарка профиля — тап открывает ту же шторку выбора фото, что и в чате
/// (см. photo_picker_sheet.dart, maxAssets: 1 — камера уже плиткой внутри
/// неё, тап по фото сразу подтверждает и загружает). Значок камеры в углу —
/// подсказка, что это кликабельно, не просто картинка.
class EditableAvatar extends StatefulWidget {
  final String? avatarUrl;
  final String sessionToken;
  final ValueChanged<Profile> onChanged;

  const EditableAvatar({
    super.key,
    required this.avatarUrl,
    required this.sessionToken,
    required this.onChanged,
  });

  @override
  State<EditableAvatar> createState() => _EditableAvatarState();
}

class _EditableAvatarState extends State<EditableAvatar> {
  bool _busy = false;

  Future<void> _openPicker() async {
    final theme = Theme.of(context);
    final hasAvatar = widget.avatarUrl != null && widget.avatarUrl!.isNotEmpty;

    final action = await showModalBottomSheet<_AvatarAction>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Выбрать фото'),
              onTap: () => Navigator.of(ctx).pop(_AvatarAction.pick),
            ),
            if (hasAvatar)
              ListTile(
                leading: Icon(
                  Icons.delete_outline_rounded,
                  color: theme.colorScheme.error,
                ),
                title: Text(
                  'Удалить фото',
                  style: TextStyle(color: theme.colorScheme.error),
                ),
                onTap: () => Navigator.of(ctx).pop(_AvatarAction.delete),
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );

    if (action == null || !mounted) return;

    switch (action) {
      case _AvatarAction.pick:
        await _pickAndUpload();
      case _AvatarAction.delete:
        await _delete();
    }
  }

  Future<void> _pickAndUpload() async {
    final picked = await pickPhotos(context, maxAssets: 1);
    if (picked == null || picked.isEmpty || !mounted) return;

    setState(() => _busy = true);
    try {
      final compressed = await compressImageBytes(
        picked.first,
        maxDimension: 640,
        targetBytes: 130 * 1024,
      );
      final profile = await ProfileRepository.create().uploadAvatar(
        widget.sessionToken,
        compressed,
        'avatar.jpg',
      );
      widget.onChanged(profile);
    } catch (e) {
      if (mounted) _showError(e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete() async {
    final confirmed = await showConfirmDialog(
      context,
      title: 'Удалить фото?',
      message: 'Аватарка будет удалена без возможности восстановить.',
    );
    if (!confirmed || !mounted) return;

    setState(() => _busy = true);
    try {
      final profile = await ProfileRepository.create().deleteAvatar(
        widget.sessionToken,
      );
      widget.onChanged(profile);
    } catch (e) {
      if (mounted) _showError(e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _showError(Object e) {
    final message = e is AppException ? e.message : 'Не удалось обновить фото';
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hasAvatar = widget.avatarUrl != null && widget.avatarUrl!.isNotEmpty;

    return GestureDetector(
      onTap: _busy ? null : _openPicker,
      child: SizedBox(
        width: 64,
        height: 64,
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            CircleAvatar(
              radius: 32,
              backgroundColor: theme.colorScheme.primaryContainer,
              backgroundImage: hasAvatar
                  ? NetworkImage(widget.avatarUrl!)
                  : null,
              onBackgroundImageError: hasAvatar ? (_, _) {} : null,
              child: !hasAvatar
                  ? Icon(
                      Icons.person_rounded,
                      color: theme.colorScheme.primary,
                      size: 32,
                    )
                  : null,
            ),
            if (_busy)
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    color: Colors.black.withValues(alpha: 0.4),
                    shape: BoxShape.circle,
                  ),
                  child: const Padding(
                    padding: EdgeInsets.all(18),
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  ),
                ),
              ),
            Positioned(
              bottom: -2,
              right: -2,
              child: Container(
                padding: const EdgeInsets.all(4),
                decoration: BoxDecoration(
                  color: theme.colorScheme.primary,
                  shape: BoxShape.circle,
                  border: Border.all(
                    color: theme.colorScheme.surface,
                    width: 2,
                  ),
                ),
                child: Icon(
                  Icons.photo_camera_rounded,
                  size: 14,
                  color: theme.colorScheme.onPrimary,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

enum _AvatarAction { pick, delete }
