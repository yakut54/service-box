import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:wechat_assets_picker/wechat_assets_picker.dart';

/// Максимум фото за один выбор — ровно лимит сервера на загрузку картинок
/// в чат (throttle `chat-image` = 10 в минуту,
/// см. app/Providers/AppServiceProvider.php). Больше выбрать физически
/// нельзя — сервер всё равно отклонит запросы после десятого.
const int kChatPhotoPickLimit = 10;

/// Свой экран выбора фото для чата — сетка последних фото с телефона,
/// плитка камеры первой, выбор нескольких сразу (по просьбе пользователя,
/// "как в Telegram"). Раньше здесь был системный ImagePicker.gallery — тот
/// путь короче кода, но выглядит как окно телефона, а не приложения, и не
/// умеет выбрать больше одной картинки. См. chat_screen.dart, метод
/// _pickImage, для истории этого решения.
///
/// Возвращает список уже прочитанных байтов картинок — не важно, взяты они
/// из галереи или сняты камерой, вызывающий код (chat_screen.dart) отдаёт
/// их дальше в существующий пайплайн сжатия/загрузки без изменений.
/// `null`, если пользователь отменил выбор.
Future<List<Uint8List>?> pickChatPhotos(BuildContext context) async {
  final accentColor = Theme.of(context).colorScheme.primary;

  final assets = await AssetPicker.pickAssets(
    context,
    pickerConfig: AssetPickerConfig(
      maxAssets: kChatPhotoPickLimit,
      requestType: RequestType.image,
      specialItemPosition: SpecialItemPosition.prepend,
      specialItemBuilder: (context, path, length) =>
          _CameraTile(accentColor: accentColor),
      pickerTheme: _darkPickerTheme(accentColor),
      textDelegate: const RussianAssetPickerTextDelegate(),
    ),
  );

  if (assets == null || assets.isEmpty) return null;

  final bytesList = <Uint8List>[];
  for (final asset in assets) {
    final bytes = await asset.originBytes;
    if (bytes != null) bytesList.add(bytes);
  }
  return bytesList.isEmpty ? null : bytesList;
}

/// Тёмная тема пикера — по образцу уже существующего полноэкранного
/// просмотра фото в чате (_FullImageViewer в chat_screen.dart), там чёрный
/// фон/белый текст захардкожены осознанно (просмотр фото, не обычный экран
/// приложения) — для пикера тот же приём консистентен.
ThemeData _darkPickerTheme(Color accentColor) {
  return ThemeData.dark(useMaterial3: true).copyWith(
    scaffoldBackgroundColor: Colors.black,
    canvasColor: Colors.black,
    appBarTheme: const AppBarTheme(
      backgroundColor: Colors.black,
      foregroundColor: Colors.white,
    ),
    colorScheme: ColorScheme.dark(primary: accentColor),
  );
}

/// Плитка камеры первой в сетке. Снимок сразу сохраняется в галерею
/// телефона (PhotoManager.editor.saveImage) — точно так же ведёт себя
/// Telegram: фото, снятое из чата, попадает и в чат, и в камеру телефона.
class _CameraTile extends StatelessWidget {
  const _CameraTile({required this.accentColor});

  final Color accentColor;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: () => _captureAndReturn(context),
      child: Container(
        color: Colors.grey.shade900,
        alignment: Alignment.center,
        child: Icon(Icons.camera_alt_rounded, color: accentColor, size: 32),
      ),
    );
  }

  Future<void> _captureAndReturn(BuildContext context) async {
    final picked = await ImagePicker().pickImage(source: ImageSource.camera);
    if (picked == null || !context.mounted) return;

    try {
      final bytes = await picked.readAsBytes();
      final asset = await PhotoManager.editor.saveImage(
        bytes,
        filename: 'servicebox_${DateTime.now().millisecondsSinceEpoch}.jpg',
      );
      if (context.mounted) {
        Navigator.of(context).pop(<AssetEntity>[asset]);
      }
    } catch (_) {
      // Не удалось сохранить снимок в галерею (например нет разрешения) —
      // само фото не потеряно физически, просто закрываем пикер ни с чем;
      // пользователь может повторить попытку.
      if (context.mounted) Navigator.of(context).pop();
    }
  }
}

/// Русские подписи пикера — в пакете из коробки только английский/китайский.
class RussianAssetPickerTextDelegate extends EnglishAssetPickerTextDelegate {
  const RussianAssetPickerTextDelegate();

  @override
  String get languageCode => 'ru';

  @override
  String get confirm => 'Готово';

  @override
  String get cancel => 'Отмена';

  @override
  String get edit => 'Изменить';

  @override
  String get loadFailed => 'Не удалось загрузить';

  @override
  String get original => 'Оригинал';

  @override
  String get preview => 'Просмотр';

  @override
  String get select => 'Выбрать';

  @override
  String get emptyList => 'Список пуст';

  @override
  String get unableToAccessAll => 'Нет доступа ко всем фото на устройстве';

  @override
  String get viewingLimitedAssetsTip =>
      'Приложению доступна только часть фото и альбомов';

  @override
  String get changeAccessibleLimitedAssets => 'Настроить доступные фото';

  @override
  String get accessAllTip =>
      'Приложению разрешён доступ только к части фото на устройстве. '
      'Откройте настройки, чтобы разрешить доступ ко всем фото.';

  @override
  String get goToSystemSettings => 'Открыть настройки';

  @override
  String get accessLimitedAssets => 'Продолжить с ограниченным доступом';

  @override
  String get accessiblePathName => 'Доступные фото';

  @override
  String get sTypeImageLabel => 'Фото';

  @override
  String get sActionPreviewHint => 'просмотр';

  @override
  String get sActionSelectHint => 'выбрать';

  @override
  String get sActionSwitchPathLabel => 'сменить альбом';

  @override
  String get sActionUseCameraHint => 'снять фото';
}
