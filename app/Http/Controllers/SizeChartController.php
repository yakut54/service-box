<?php

namespace App\Http\Controllers;

use App\Models\SizeChart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD размерных сеток магазина (только админка). Мобилке сетка отдаётся
 * вложенной в товар (ProductController::show → size_chart), отдельного
 * widget-эндпоинта нет.
 */
class SizeChartController extends Controller
{
    // GET /admin/size-charts
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SizeChart::orderBy('name')->get(),
        ]);
    }

    // POST /admin/size-charts
    public function store(Request $request): JsonResponse
    {
        $chart = SizeChart::create($this->validated($request));

        return response()->json(['data' => $chart], 201);
    }

    // PUT /admin/size-charts/{sizeChart}
    public function update(Request $request, string $sizeChart): JsonResponse
    {
        $chart = SizeChart::findOrFail($sizeChart);
        $chart->update($this->validated($request));

        return response()->json(['data' => $chart->fresh()]);
    }

    // DELETE /admin/size-charts/{sizeChart}
    // Товары не удаляются — products.size_chart_id обнуляется (ON DELETE SET NULL).
    public function destroy(string $sizeChart): JsonResponse
    {
        SizeChart::findOrFail($sizeChart)->delete();

        return response()->json(['message' => 'Размерная сетка удалена']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind'        => 'nullable|in:clothing,shoes,custom',
            'name'        => 'required|string|max:255',
            'columns'     => 'required|array|min:1|max:12',
            'columns.*'   => 'required|string|max:120',
            'rows'        => 'nullable|array|max:60',
            'rows.*'      => 'array',
            'rows.*.*'    => 'nullable|string|max:120',
        ]);

        $data['kind'] ??= 'custom';
        $data['rows'] ??= [];

        // Каждая строка ровно по числу колонок — недостающие ячейки добиваем
        // пустыми, лишние отрезаем; целиком пустые строки убираем.
        $width = count($data['columns']);
        $data['rows'] = collect($data['rows'])
            ->map(fn ($row) => array_map(
                fn ($i) => (string) ($row[$i] ?? ''),
                range(0, $width - 1),
            ))
            ->filter(fn ($row) => collect($row)->contains(fn ($c) => trim($c) !== ''))
            ->values()
            ->all();

        return $data;
    }
}
