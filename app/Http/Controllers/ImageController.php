<?php

namespace App\Http\Controllers;

use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|max:1024',
        ], [
            'image.image' => 'Файл должен быть изображением',
            'image.max'   => 'Максимальный размер файла — 1 МБ',
        ]);

        $file     = $request->file('image');
        $ext      = $file->guessExtension() ?? 'jpg';
        $filename = Str::uuid() . '.' . $ext;
        $path     = $file->storeAs('uploads', $filename, 'public');
        $url      = Storage::disk('public')->url($path);

        return response()->json(['url' => $url]);
    }

    public function delete(Request $request): JsonResponse
    {
        $request->validate(['url' => 'required|string']);

        StorageService::deleteByUrl($request->input('url'));

        return response()->json(['ok' => true]);
    }
}
