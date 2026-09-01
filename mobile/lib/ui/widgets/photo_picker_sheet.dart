import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:photo_manager/photo_manager.dart';
import 'package:photo_manager_image_provider/photo_manager_image_provider.dart';

import 'primary_submit_button.dart';

/// Максимум фото за один выбор в чате — ровно лимит сервера на загрузку
/// картинок (throttle `chat-image` = 10 в минуту, см.
/// app/Providers/AppServiceProvider.php). Больше выбрать физически нельзя —
/// сервер всё равно отклонит запросы после десятого.
const int kChatPhotoPickLimit = 10;

const int _pageSize = 60;
const _thumbnailSize = ThumbnailSize.square(200);

/// Шторка выбора фото снизу — как в Telegram/WhatsApp: выезжает поверх
/// текущего экрана (не уводит на отдельную страницу), тянется вверх драгом
/// по сетке. Один переиспользуемый компонент на два сценария:
/// - `maxAssets > 1` (чат) — мультивыбор с нумерованными кружками и кнопкой
///   «Готово (N/M)»;
/// - `maxAssets == 1` (аватар профиля) — тап по фото сразу подтверждает и
///   закрывает шторку, отдельная кнопка не нужна для выбора одной картинки.
///
/// Раньше здесь был `wechat_assets_picker` (AssetPicker.pickAssets) — у
/// пакета нет режима шторки в принципе, весь публичный API жёстко пушит
/// отдельный full-screen route (см. PLAN.md). Собственная сетка на
/// `photo_manager` вместо борьбы с чужой навигацией.
///
/// Возвращает список уже прочитанных байтов картинок — не важно, взяты они
/// из галереи или сняты камерой. `null`, если пользователь ничего не выбрал.
Future<List<Uint8List>?> pickPhotos(
  BuildContext context, {
  int maxAssets = kChatPhotoPickLimit,
}) {
  return showModalBottomSheet<List<Uint8List>>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (_) => _PhotoPickerSheet(maxAssets: maxAssets),
  );
}

class _PhotoPickerSheet extends StatefulWidget {
  final int maxAssets;

  const _PhotoPickerSheet({required this.maxAssets});

  @override
  State<_PhotoPickerSheet> createState() => _PhotoPickerSheetState();
}

class _PhotoPickerSheetState extends State<_PhotoPickerSheet> {
  PermissionState? _permission;
  final List<AssetEntity> _assets = [];
  final List<AssetEntity> _selected = [];
  AssetPathEntity? _recentPath;
  int _page = 0;
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = true;
  bool _confirming = false;

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    final permission = await PhotoManager.requestPermissionExtend();
    if (!mounted) return;
    setState(() => _permission = permission);
    if (!permission.hasAccess) {
      setState(() => _loading = false);
      return;
    }
    await _loadMore();
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_hasMore) return;
    _loadingMore = true;
    try {
      _recentPath ??= (await PhotoManager.getAssetPathList(
        type: RequestType.image,
        onlyAll: true,
      )).firstOrNull;

      final path = _recentPath;
      if (path == null) {
        if (mounted) setState(() => _hasMore = false);
        return;
      }

      final page = await path.getAssetListPaged(page: _page, size: _pageSize);
      if (!mounted) return;
      setState(() {
        _assets.addAll(page);
        _page++;
        _hasMore = page.length == _pageSize;
      });
    } finally {
      _loadingMore = false;
      if (mounted) setState(() => _loading = false);
    }
  }

  bool get _isSingle => widget.maxAssets == 1;

  Future<void> _onAssetTap(AssetEntity asset) async {
    if (_isSingle) {
      await _confirmWith([asset]);
      return;
    }
    setState(() {
      if (_selected.contains(asset)) {
        _selected.remove(asset);
      } else if (_selected.length < widget.maxAssets) {
        _selected.add(asset);
      }
    });
  }

  Future<void> _onCaptured(AssetEntity asset) async {
    if (_isSingle) {
      await _confirmWith([asset]);
      return;
    }
    setState(() {
      _assets.insert(0, asset);
      if (_selected.length < widget.maxAssets) _selected.add(asset);
    });
  }

  Future<void> _confirmWith(List<AssetEntity> assets) async {
    if (_confirming || !mounted) return;
    setState(() => _confirming = true);
    final bytesList = <Uint8List>[];
    for (final asset in assets) {
      final bytes = await asset.originBytes;
      if (bytes != null) bytesList.add(bytes);
    }
    if (!mounted) return;
    Navigator.of(context).pop(bytesList.isEmpty ? null : bytesList);
  }

  void _confirmSelection() => _confirmWith(_selected);

  @override
  Widget build(BuildContext context) {
    final accent = Theme.of(context).colorScheme.primary;

    return DraggableScrollableSheet(
      initialChildSize: 0.65,
      minChildSize: 0.4,
      maxChildSize: 0.95,
      expand: false,
      builder: (context, scrollController) => DecoratedBox(
        decoration: const BoxDecoration(
          color: Colors.black,
          borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
        ),
        child: Column(
          children: [
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 10),
              child: _DragHandle(),
            ),
            Expanded(child: _buildBody(scrollController, accent)),
            // Панель всегда на месте для мультивыбора (даже с 0 выбранных,
            // просто задизейблена) — иначе вёрстка шторки прыгает в момент
            // выбора первого фото.
            if (!_isSingle) _buildConfirmBar(),
          ],
        ),
      ),
    );
  }

  Widget _buildBody(ScrollController scrollController, Color accent) {
    final permission = _permission;

    if (permission != null && !permission.hasAccess) {
      return _PermissionDeniedView(limited: permission.isLimited);
    }

    if (_loading) {
      return Center(child: CircularProgressIndicator(color: accent));
    }

    return NotificationListener<ScrollNotification>(
      onNotification: (notification) {
        if (notification.metrics.pixels >
            notification.metrics.maxScrollExtent - 800) {
          _loadMore();
        }
        return false;
      },
      child: GridView.builder(
        controller: scrollController,
        padding: const EdgeInsets.all(2),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 4,
          crossAxisSpacing: 2,
          mainAxisSpacing: 2,
        ),
        itemCount: _assets.length + 1,
        itemBuilder: (context, index) {
          if (index == 0) {
            return _CameraTile(accentColor: accent, onCaptured: _onCaptured);
          }
          final asset = _assets[index - 1];
          final position = _selected.indexOf(asset);
          return _AssetTile(
            asset: asset,
            selected: position != -1,
            selectionNumber: position == -1 ? null : position + 1,
            singleSelect: _isSingle,
            accentColor: accent,
            onTap: () => _onAssetTap(asset),
          );
        },
      ),
    );
  }

  Widget _buildConfirmBar() {
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: PrimarySubmitButton(
          label: 'Готово (${_selected.length}/${widget.maxAssets})',
          loading: _confirming,
          onPressed: _selected.isEmpty ? null : _confirmSelection,
        ),
      ),
    );
  }
}

class _DragHandle extends StatelessWidget {
  const _DragHandle();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 36,
      height: 4,
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.3),
        borderRadius: BorderRadius.circular(2),
      ),
    );
  }
}

class _AssetTile extends StatelessWidget {
  final AssetEntity asset;
  final bool selected;
  final int? selectionNumber;
  final bool singleSelect;
  final Color accentColor;
  final VoidCallback onTap;

  const _AssetTile({
    required this.asset,
    required this.selected,
    required this.selectionNumber,
    required this.singleSelect,
    required this.accentColor,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: onTap,
      child: Stack(
        fit: StackFit.expand,
        children: [
          AssetEntityImage(
            asset,
            isOriginal: false,
            thumbnailSize: _thumbnailSize,
            fit: BoxFit.cover,
          ),
          if (!singleSelect)
            Positioned(
              top: 6,
              right: 6,
              child: _SelectionCircle(number: selectionNumber, accentColor: accentColor),
            ),
        ],
      ),
    );
  }
}

class _SelectionCircle extends StatelessWidget {
  final int? number;
  final Color accentColor;

  const _SelectionCircle({required this.number, required this.accentColor});

  @override
  Widget build(BuildContext context) {
    final selected = number != null;
    return Container(
      width: 24,
      height: 24,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: selected ? accentColor : Colors.black.withValues(alpha: 0.35),
        border: Border.all(color: Colors.white, width: 1.5),
      ),
      child: selected
          ? Text(
              '$number',
              style: const TextStyle(
                color: Colors.white,
                fontSize: 13,
                fontWeight: FontWeight.w700,
              ),
            )
          : null,
    );
  }
}

/// Плитка камеры первой в сетке. Снимок сразу сохраняется в галерею телефона
/// (PhotoManager.editor.saveImage) — так же ведёт себя Telegram: фото,
/// снятое из чата, попадает и в чат, и в камеру телефона. Отдаёт результат
/// наверх через колбэк — сама шторку не закрывает (это решает родитель:
/// сразу подтвердить для одиночного выбора или добавить в список для
/// мультивыбора), в отличие от старой версии, которая была собственным
/// route и просто пилила Navigator.pop.
class _CameraTile extends StatelessWidget {
  final Color accentColor;
  final ValueChanged<AssetEntity> onCaptured;

  const _CameraTile({required this.accentColor, required this.onCaptured});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: () => _capture(context),
      child: Container(
        color: Colors.grey.shade900,
        alignment: Alignment.center,
        child: Icon(Icons.camera_alt_rounded, color: accentColor, size: 32),
      ),
    );
  }

  Future<void> _capture(BuildContext context) async {
    final picked = await ImagePicker().pickImage(source: ImageSource.camera);
    if (picked == null) return;

    try {
      final bytes = await picked.readAsBytes();
      final asset = await PhotoManager.editor.saveImage(
        bytes,
        filename: 'servicebox_${DateTime.now().millisecondsSinceEpoch}.jpg',
      );
      onCaptured(asset);
    } catch (_) {
      // Не удалось сохранить снимок в галерею (например нет разрешения) —
      // само фото не потеряно физически, просто не добавляем в выбор;
      // пользователь может повторить попытку.
    }
  }
}

class _PermissionDeniedView extends StatelessWidget {
  final bool limited;

  const _PermissionDeniedView({required this.limited});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.photo_library_outlined, color: Colors.white54, size: 48),
            const SizedBox(height: 12),
            Text(
              limited
                  ? 'Приложению доступна только часть фото на устройстве'
                  : 'Нет доступа к фото на устройстве',
              style: const TextStyle(color: Colors.white),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            OutlinedButton(
              style: OutlinedButton.styleFrom(foregroundColor: Colors.white),
              onPressed: PhotoManager.openSetting,
              child: const Text('Открыть настройки'),
            ),
          ],
        ),
      ),
    );
  }
}
