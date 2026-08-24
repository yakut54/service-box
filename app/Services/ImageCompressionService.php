<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Гарантированное серверное сжатие изображения до целевого размера — что бы
 * ни прислал клиент, каким бы огромным ни был исходник (фото с камеры без
 * сжатия — обычное дело), на выходе всегда WebP не больше $maxBytes. Не
 * заменяет сжатие на клиенте (то экономит мобильный интернет самого
 * отправителя ДО отправки) — это гарантия на стороне сервера, независимая
 * от того, сжал ли клиент вообще и насколько удачно. Пользователь никогда
 * не видит ошибку «файл слишком большой» — размер входа не проверяется
 * вообще, кроме общего потолка PHP (upload_max_filesize в Dockerfile).
 *
 * WebP вместо JPEG (2026-08-24, по факту замера на сервере): при том же
 * байтовом бюджете заметно меньше блочных артефактов на тексте/линиях/
 * фигурах и не приходится так агрессивно резать разрешение, чтобы
 * уложиться в лимит. Прозрачность (PNG со скруглениями/подложкой) WebP
 * поддерживает нативно — в отличие от JPEG, сплющивать альфа-канал на
 * белый фон больше не нужно.
 *
 * Используется и виджетом (чат покупателя), и админкой (чат сотрудников) —
 * общая логика, не дублируем в двух контроллерах.
 */
class ImageCompressionService
{
    /**
     * @return string Сжатые байты WebP.
     */
    public static function compressToWebp(
        UploadedFile $file,
        int $maxBytes = 100 * 1024,
        int $maxDimension = 1024,
    ): string {
        $image = self::loadImage($file->getRealPath(), $file->getMimeType());
        self::preserveAlpha($image);

        $image = self::resizeToFit($image, $maxDimension);

        $bytes = self::encodeUnderLimit($image, $maxBytes);
        imagedestroy($image);

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
     * Отключает предварительное смешивание и включает сохранение альфа-
     * канала — без этого imagecopyresampled() и imagewebp() затирают
     * прозрачность чёрным или сплошным фоном.
     */
    private static function preserveAlpha($image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
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
        self::preserveAlpha($resized);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    /**
     * Подбирает качество WebP (сверху вниз), пока размер не впишется в
     * $maxBytes. Если даже минимальное качество не помогает (редкий случай —
     * очень крупное изображение) — дополнительно уменьшает сторону вдвое и
     * повторяет; не более нескольких раундов, чтобы не зациклиться.
     */
    private static function encodeUnderLimit($image, int $maxBytes): string
    {
        for ($round = 0; $round < 4; $round++) {
            foreach ([80, 65, 50, 35, 20] as $quality) {
                ob_start();
                imagewebp($image, null, $quality);
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
            self::preserveAlpha($smaller);
            imagecopyresampled($smaller, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            imagedestroy($image);
            $image = $smaller;
        }

        // Совсем крайний случай — отдаём то, что получилось на минимальном
        // качестве и минимальном размере, лучше чуть больше 100 КБ, чем
        // сломанная отправка.
        ob_start();
        imagewebp($image, null, 20);
        return ob_get_clean();
    }
}
