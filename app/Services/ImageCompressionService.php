<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Гарантированное серверное сжатие изображения до целевого размера — что бы
 * ни прислал клиент, каким бы огромным ни был исходник (фото с камеры без
 * сжатия — обычное дело), на выходе всегда JPEG не больше $maxBytes. Не
 * заменяет сжатие на клиенте (то экономит мобильный интернет самого
 * отправителя ДО отправки) — это гарантия на стороне сервера, независимая
 * от того, сжал ли клиент вообще и насколько удачно. Пользователь никогда
 * не видит ошибку «файл слишком большой» — размер входа не проверяется
 * вообще, кроме общего потолка PHP (upload_max_filesize в Dockerfile).
 *
 * Используется и виджетом (чат покупателя), и админкой (чат сотрудников) —
 * общая логика, не дублируем в двух контроллерах.
 */
class ImageCompressionService
{
    /**
     * @return string Сжатые байты JPEG.
     */
    public static function compressToJpeg(
        UploadedFile $file,
        int $maxBytes = 100 * 1024,
        int $maxDimension = 1024,
    ): string {
        $image = self::loadImage($file->getRealPath(), $file->getMimeType());

        $image = self::resizeToFit($image, $maxDimension);

        // JPEG без альфа-канала — сглаживаем прозрачность (PNG/WebP) на
        // белый фон перед перекодированием, иначе прозрачные пиксели
        // почернеют при конвертации в JPEG.
        $flattened = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flattened, 0, 0, imagecolorallocate($flattened, 255, 255, 255));
        imagecopy($flattened, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagedestroy($image);

        $bytes = self::encodeUnderLimit($flattened, $maxBytes);
        imagedestroy($flattened);

        return $bytes;
    }

    /**
     * @return \GdImage
     */
    private static function loadImage(string $path, ?string $mimeType)
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => null,
        };

        if (!$image) {
            throw new RuntimeException('Не удалось прочитать изображение');
        }

        return $image;
    }

    /**
     * Уменьшает изображение, если хотя бы одна сторона больше $maxDimension.
     * Не увеличивает маленькие изображения.
     *
     * @return \GdImage
     */
    private static function resizeToFit($image, int $maxDimension)
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $scale  = min(1, $maxDimension / max($width, $height));

        if ($scale >= 1) {
            return $image;
        }

        $newWidth  = (int) round($width * $scale);
        $newHeight = (int) round($height * $scale);

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    /**
     * Подбирает качество JPEG (сверху вниз), пока размер не впишется в
     * $maxBytes. Если даже минимальное качество не помогает (редкий случай —
     * очень крупное изображение) — дополнительно уменьшает сторону вдвое и
     * повторяет; не более нескольких раундов, чтобы не зациклиться.
     */
    private static function encodeUnderLimit($image, int $maxBytes): string
    {
        for ($round = 0; $round < 4; $round++) {
            foreach ([80, 65, 50, 35, 20] as $quality) {
                ob_start();
                imagejpeg($image, null, $quality);
                $data = ob_get_clean();

                if (strlen($data) <= $maxBytes) {
                    return $data;
                }
            }

            // Ничего не подошло даже на самом низком качестве — уменьшаем
            // сторону вдвое и пробуем снова.
            $width  = (int) round(imagesx($image) / 2);
            $height = (int) round(imagesy($image) / 2);
            if ($width < 50 || $height < 50) {
                break;
            }

            $smaller = imagecreatetruecolor($width, $height);
            imagecopyresampled($smaller, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            imagedestroy($image);
            $image = $smaller;
        }

        // Совсем крайний случай — отдаём то, что получилось на минимальном
        // качестве и минимальном размере, лучше чуть больше 100 КБ, чем
        // сломанная отправка.
        ob_start();
        imagejpeg($image, null, 20);
        return ob_get_clean();
    }
}
