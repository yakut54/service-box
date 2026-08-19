import 'package:flutter/foundation.dart';
import 'package:image/image.dart' as img;

class _CompressParams {
  final Uint8List bytes;
  final int maxDimension;
  final int targetBytes;

  const _CompressParams({
    required this.bytes,
    required this.maxDimension,
    required this.targetBytes,
  });
}

/// Сжимает фото до целевого размера в байтах — уменьшает сторону, затем
/// подбирает качество JPEG. Работает в отдельном изоляте (compute), чтобы
/// не подвешивать интерфейс на больших фото с камеры.
Future<Uint8List> compressImageBytes(
  Uint8List bytes, {
  required int maxDimension,
  required int targetBytes,
}) {
  return compute(
    _compress,
    _CompressParams(
      bytes: bytes,
      maxDimension: maxDimension,
      targetBytes: targetBytes,
    ),
  );
}

Uint8List _compress(_CompressParams params) {
  var image = img.decodeImage(params.bytes);
  if (image == null) return params.bytes;

  if (image.width > params.maxDimension || image.height > params.maxDimension) {
    image = img.copyResize(
      image,
      width: image.width >= image.height ? params.maxDimension : null,
      height: image.height > image.width ? params.maxDimension : null,
    );
  }

  var quality = 85;
  var result = Uint8List.fromList(img.encodeJpg(image, quality: quality));
  while (result.lengthInBytes > params.targetBytes && quality > 30) {
    quality -= 10;
    result = Uint8List.fromList(img.encodeJpg(image, quality: quality));
  }
  return result;
}
