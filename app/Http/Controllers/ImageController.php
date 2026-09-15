<?php

namespace App\Http\Controllers;

use App\Services\ImageCompressionService;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    /**
     * Обложки/галерея товаров, логотип магазина, аватар сотрудника.
     *
     * ВАЖНО: правило `image` в Laravel не принимает параметров — это ярлык
     * для `mimes:jpg,jpeg,png,bmp,gif,svg,webp` целиком, `image:...` со
     * списком типов молча его игнорирует, пропуская svg как ни в чём не
     * бывало. SVG может нести <script> — сработает при открытии файла в
     * отдельной вкладке (не в <img>, там браузер сам сажает SVG в песочницу,
     * но кто угодно может открыть картинку по прямой ссылке). Та же дыра уже
     * была найдена и закрыта в ChatController/Admin\ChatController — этот
     * эндпоинт правился отдельно, а точно так же принимал svg (найдено
     * аудитом безопасности 2026-09-15). Файл всегда декодируется и
     * перекодируется через GD (см. ImageCompressionService) — то, что не
     * распознаётся как настоящий JPEG/PNG/WebP, до диска не долетает вообще.
     *
     * Лимиты выше, чем у чата (там 100 КБ/1024px под миниатюру) — этот
     * эндпоинт отдаёт обложки/логотип, админка уже сжимает клиентски до
     * 1 МБ/1920px (см. useImageCompression.ts) — здесь только страховка на
     * случай прямого вызова API мимо интерфейса, не второй проход сжатия,
     * заметно портящий уже сжатую картинку.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|file|mimes:jpeg,png,webp|max:15360',
        ], [
            'image.mimes' => 'Файл должен быть изображением (JPEG, PNG или WebP)',
            'image.max'   => 'Максимальный размер файла — 15 МБ',
        ]);

        $compressed = ImageCompressionService::compressToWebp($request->file('image'), 1536 * 1024, 1920);

        $filename = Str::uuid() . '.webp';
        Storage::disk('public')->put('uploads/' . $filename, $compressed);
        $url = Storage::disk('public')->url('uploads/' . $filename);

        return response()->json(['url' => $url]);
    }

    public function delete(Request $request): JsonResponse
    {
        $request->validate(['url' => 'required|string']);

        StorageService::deleteByUrl($request->input('url'));

        return response()->json(['ok' => true]);
    }
}
