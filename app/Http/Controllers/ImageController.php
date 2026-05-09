<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ], [
            'image.image'    => 'Файл должен быть изображением',
            'image.mimes'    => 'Только JPG, PNG или WEBP',
            'image.max'      => 'Максимальный размер файла — 5 МБ',
        ]);

        $file      = $request->file('image');
        $filename  = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path      = $file->storeAs('products', $filename, 'public');
        $url       = Storage::disk('public')->url($path);

        return response()->json(['url' => $url]);
    }

    public function delete(Request $request): JsonResponse
    {
        $request->validate(['url' => 'required|string']);

        $url      = $request->input('url');
        $basePath = Storage::disk('public')->url('');
        $path     = ltrim(str_replace($basePath, '', $url), '/');

        if (!str_starts_with($path, 'products/')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        Storage::disk('public')->delete($path);

        return response()->json(['ok' => true]);
    }
}
