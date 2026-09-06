<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Discount;
use App\Models\Product;
use App\Services\DiscountService;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ProductController extends Controller
{
    public function __construct(private readonly DiscountService $discountService) {}

    /**
     * Проставить badge-скидку (auto-apply, см. DiscountService::bestBadge)
     * каждому товару в коллекции — только для /widget/*, админке это не нужно.
     * Одна выборка активных скидок на всю коллекцию, а не по одной на товар.
     * discount_price — цена ПОСЛЕ этой скидки, для зачёркнутой старой цены
     * на клиенте (старая цена = обычная product.price). discount_ends_at —
     * когда скидка перестанет действовать (null — бессрочно), чтобы было
     * видно в приложении, что акция ограничена по времени.
     */
    private function attachBadges(iterable $products): void
    {
        $activeAutoDiscounts = Discount::active()->whereNull('code')->orderByDesc('priority')->get();
        if ($activeAutoDiscounts->isEmpty()) return;

        foreach ($products as $product) {
            $badge = $this->discountService->bestBadge($product, $activeAutoDiscounts);
            $product->discount_percent  = $badge['percent'] ?? null;
            $product->discount_price    = $badge ? $product->price - $badge['amount'] : null;
            $product->discount_ends_at  = $badge['ends_at'] ?? null;
        }
    }

    /**
     * Get list of products
     *
     * GET /api/admin/products
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->with('category:id,name,slug,age_restricted,no_return');

        if ($request->has('type')) {
            $query->ofType($request->type);
        }

        // Entitlements (только для /widget/*, не для админки) — см. МФ4 в PLAN.md.
        // Физические товары всегда доступны, digital/service гейтятся по флагу.
        if ($shop = $request->get('_shop')) {
            $hiddenTypes = [];
            if (!$shop->hasFeature('digital_goods')) $hiddenTypes[] = 'digital';
            if (!$shop->hasFeature('booking'))        $hiddenTypes[] = 'service';
            if ($hiddenTypes) $query->whereNotIn('type', $hiddenTypes);
        }

        if ($request->has('active')) {
            $active = filter_var($request->active, FILTER_VALIDATE_BOOLEAN);
            if ($active) {
                $query->active();
            } else {
                $query->where('is_active', false);
            }
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('search')) {
            // Только по названию — поиск по description раньше давал
            // случайные совпадения на коротких запросах (например, «св»
            // находил «Картофель молодой» из-за фразы «свежий урожай»
            // в описании — ожидаемо только у «Морковь свежая»/«Свёкла»).
            $query->where('name', 'ILIKE', '%'.$request->search.'%');
        }

        $query->orderBy('sort_order')->orderBy('name');
        $query->withAvg(['reviews as rating' => fn($q) => $q->where('is_published', true)], 'rating')
              ->withCount(['reviews as review_count' => fn($q) => $q->where('is_published', true)]);

        $products = $query->get()->map(function ($product) {
            $product->loadDetails();
            // withAvg returns numeric as string from PostgreSQL — cast to float
            $product->rating = $product->rating !== null ? (float) $product->rating : null;
            return $product;
        });

        if ($request->get('_shop')) {
            $this->attachBadges($products);
        }

        return response()->json([
            'data'  => $products,
            'count' => $products->count(),
        ]);
    }

    /**
     * Store a new product
     *
     * POST /api/admin/products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->only([
            'type',
            'name',
            'description',
            'price',
            'compare_price',
            'currency',
            'image_url',
            'is_active',
            'category_id',
            'size_chart_id',
            'sort_order',
        ]));

        $this->storeProductDetails($product, $request);

        $product->load('category:id,name,slug,age_restricted,no_return');
        $product->loadDetails();

        return response()->json([
            'message' => 'Товар создан',
            'data'    => $product,
        ], 201);
    }

    /**
     * Get single product
     *
     * GET /api/admin/products/{product}
     */
    public function show(Request $request, string $product): JsonResponse
    {
        $product = Product::with(['category:id,name,slug,age_restricted,no_return', 'images:id,product_id,url,sort_order'])
                          ->withAvg(['reviews as rating' => fn($q) => $q->where('is_published', true)], 'rating')
                          ->withCount(['reviews as review_count' => fn($q) => $q->where('is_published', true)])
                          ->findOrFail($product);

        // Entitlements (только для /widget/*) — прячем товар, как будто его нет.
        if ($shop = $request->get('_shop')) {
            if ($product->type === 'digital' && !$shop->hasFeature('digital_goods')) abort(404);
            if ($product->type === 'service' && !$shop->hasFeature('booking')) abort(404);
        }

        $product->loadDetails();
        $product->rating = $product->rating !== null ? (float) $product->rating : null;

        if ($request->get('_shop')) {
            $this->attachBadges([$product]);
        }

        return response()->json([
            'data' => $product,
        ]);
    }

    /**
     * Update product
     *
     * PUT /api/admin/products/{product}
     */
    public function update(UpdateProductRequest $request, string $product): JsonResponse
    {
        $product = Product::findOrFail($product);

        $oldImageUrl = $product->image_url;

        // Остатки ДО обновления — для «сообщить о поступлении» (0 → >0).
        $oldStock = [
            'simple'   => $product->physical()->value('stock_quantity') ?? 0,
            'variants' => $product->variants()->pluck('stock_quantity', 'id')->all(),
        ];

        $product->update($request->only([
            'type',
            'name',
            'description',
            'price',
            'compare_price',
            'currency',
            'image_url',
            'is_active',
            'category_id',
            'size_chart_id',
            'sort_order',
        ]));

        if ($request->has('image_url') && $request->image_url !== $oldImageUrl) {
            StorageService::deleteByUrl($oldImageUrl);
        }

        $this->updateProductDetails($product, $request);

        $this->notifyBackInStock($product, $oldStock);

        $product->refresh()->load('category:id,name,slug,age_restricted,no_return')->loadDetails();

        return response()->json([
            'message' => 'Товар обновлён',
            'data'    => $product,
        ]);
    }

    /**
     * Остаток товара/варианта перешёл из 0 в положительный → уведомить
     * подписчиков «сообщить о поступлении».
     *
     * @param array{simple:int, variants:array<string,int>} $oldStock
     */
    protected function notifyBackInStock(Product $product, array $oldStock): void
    {
        $hasVariants = $product->options()->exists();

        if (!$hasVariants) {
            $new = $product->physical()->value('stock_quantity') ?? 0;
            if (($oldStock['simple'] ?? 0) <= 0 && $new > 0) {
                \App\Jobs\NotifyBackInStock::dispatchFor($product->id, null);
            }
            return;
        }

        // sync сохраняет id совпавших по кортезу комбинаций — сравниваем по id.
        foreach ($product->variants()->get(['id', 'stock_quantity']) as $v) {
            $old = $oldStock['variants'][$v->id] ?? null;
            if ($old !== null && $old <= 0 && $v->stock_quantity > 0) {
                \App\Jobs\NotifyBackInStock::dispatchFor($product->id, $v->id);
            }
        }
    }

    /**
     * Delete product
     *
     * DELETE /api/admin/products/{product}
     */
    public function destroy(string $product): JsonResponse
    {
        $product = Product::with('images')->findOrFail($product);

        $imageUrl = $product->image_url;
        $galleryUrls = $product->images->pluck('url');
        $product->delete();
        StorageService::deleteByUrl($imageUrl);
        $galleryUrls->each(fn ($url) => StorageService::deleteByUrl($url));

        return response()->json([
            'message' => 'Товар удалён',
        ]);
    }

    protected function prepareDigital(array $data): array
    {
        if (isset($data['file_size_mb'])) {
            $data['file_size_bytes'] = $data['file_size_mb'] !== null
                ? (int) round((float) $data['file_size_mb'] * 1024 * 1024)
                : null;
            unset($data['file_size_mb']);
        }
        return $data;
    }

    /**
     * Штучный и весовые режимы ведут остаток в разных полях
     * (stock_quantity шт. / stock_weight_grams г) — форма шлёт весь блок
     * physical целиком независимо от выбранного режима (см. ProductEditView.vue),
     * поэтому здесь обнуляем то поле, которое для текущего sale_mode не
     * актуально. Раньше это не делалось вообще — отсюда мусорные остатки у
     * весовых товаров (см. PLAN.md, «Остаток на складе для весовых товаров»).
     */
    protected function normalizePhysical(array $data): array
    {
        if (($data['sale_mode'] ?? 'piece') === 'piece') {
            $data['stock_weight_grams'] = 0;
        } else {
            $data['stock_quantity'] = 0;
            // Цена за единицу упаковки — понятие только для штучного товара;
            // у весового уже есть цена за кг.
            $data['units_per_pack'] = null;
            $data['unit_label'] = null;
        }

        // Пустые строки → NULL, чтобы карточка не рисовала «· /» без единицы.
        foreach (['unit_label', 'marking_code'] as $k) {
            if (isset($data[$k]) && trim((string) $data[$k]) === '') {
                $data[$k] = null;
            }
        }

        return $data;
    }

    protected function storeProductDetails(Product $product, Request $request): void
    {
        if ($product->type === 'physical' && $request->has('physical')) {
            $product->physical()->create($this->normalizePhysical($request->physical));
        }

        if ($product->type === 'digital' && $request->has('digital')) {
            $product->digital()->create($this->prepareDigital($request->digital));
        }

        if ($product->type === 'service' && $request->has('service')) {
            $product->service()->create($request->service);
        }

        $this->syncAttributes($product, $request);
        $this->syncOptionsAndVariants($product, $request);
    }

    private const TUPLE_SEP = "\x1f";

    private static function tupleKey(array $values): string
    {
        return implode(self::TUPLE_SEP, array_map(fn ($v) => trim((string) $v), $values));
    }

    /**
     * Опции + варианты товара (одежда/обувь). Ключ 'options' отсутствует в
     * запросе → не трогаем (внешний API v1 варианты не шлёт). Варианты только
     * у штучного физического товара; в остальных случаях всё зачищается.
     *
     * Опции — replace-all (это каталог выбора, варианты ссылаются на значения
     * текстом, не FK). Варианты — sync по кортежу option_values: совпавшие
     * обновляем на месте (сохраняя id и остаток из формы), новые создаём,
     * пропавшие удаляем — чтобы order_items.variant_id пережил пере-сохранение.
     */
    protected function syncOptionsAndVariants(Product $product, Request $request): void
    {
        if (!$request->has('options')) {
            return;
        }

        $saleMode = $request->input('physical.sale_mode')
            ?? $product->physical()->value('sale_mode')
            ?? 'piece';
        $variantsAllowed = $product->type === 'physical' && $saleMode === 'piece';

        $options = collect($variantsAllowed ? $request->input('options', []) : [])
            ->map(fn ($o) => [
                'name'   => trim((string) ($o['name'] ?? '')),
                'values' => collect($o['values'] ?? [])
                    ->map(fn ($v) => trim((string) $v))
                    ->filter()->unique()->values()->all(),
            ])
            ->filter(fn ($o) => $o['name'] !== '' && count($o['values']) > 0)
            ->take(3)
            ->values();

        // --- опции ---
        $product->options()->delete();
        foreach ($options as $i => $o) {
            $opt = $product->options()->create(['name' => $o['name'], 'position' => $i + 1]);
            foreach ($o['values'] as $j => $val) {
                $opt->values()->create(['value' => $val, 'position' => $j]);
            }
        }

        if ($options->isEmpty()) {
            $product->variants()->delete();
            return;
        }

        // допустимые комбинации = декартово произведение значений опций
        $allowed = array_map([self::class, 'tupleKey'], $this->cartesian($options->pluck('values')->all()));
        $width   = $options->count();

        $desired = collect($request->input('variants', []))
            ->map(function ($v) use ($width) {
                $vals = collect($v['option_values'] ?? [])->map(fn ($x) => trim((string) $x))->all();
                $vals = array_map(fn ($i) => $vals[$i] ?? '', range(0, $width - 1));
                $price = Arr::get($v, 'price');

                return [
                    'option_values'   => $vals,
                    'sku'             => trim((string) Arr::get($v, 'sku', '')) ?: null,
                    'price'           => ($price === null || $price === '') ? null : max(0, (int) $price),
                    'stock_quantity'  => max(0, (int) Arr::get($v, 'stock_quantity', 0)),
                    'allow_backorder' => (bool) Arr::get($v, 'allow_backorder', false),
                    'image_url'       => trim((string) Arr::get($v, 'image_url', '')) ?: null,
                    'is_active'       => (bool) Arr::get($v, 'is_active', true),
                ];
            })
            ->filter(fn ($v) => !in_array('', $v['option_values'], true)
                && in_array(self::tupleKey($v['option_values']), $allowed, true))
            ->keyBy(fn ($v) => self::tupleKey($v['option_values']));

        $existing = $product->variants()->get()
            ->keyBy(fn ($row) => self::tupleKey((array) $row->option_values));

        foreach ($existing as $key => $row) {
            if (!$desired->has($key)) {
                $row->delete();
            }
        }

        $pos = 0;
        foreach ($desired as $key => $data) {
            $data['position'] = $pos++;
            if ($existing->has($key)) {
                $existing[$key]->update($data);
            } else {
                $product->variants()->create($data);
            }
        }
    }

    /** Декартово произведение списков значений опций. @return array<int,array<int,string>> */
    private function cartesian(array $lists): array
    {
        $result = [[]];
        foreach ($lists as $list) {
            $next = [];
            foreach ($result as $prefix) {
                foreach ($list as $item) {
                    $next[] = [...$prefix, $item];
                }
            }
            $result = $next;
        }
        return $result;
    }

    /**
     * Произвольные характеристики «label: value» — перезаписываются целиком
     * при каждом сохранении товара. Ключ 'attributes' в запросе отсутствует →
     * не трогаем (форма всегда его шлёт, но внешний API v1 может и не слать).
     * Пустые строки и лишние поля отбрасываем, порядок = порядок в форме.
     */
    protected function syncAttributes(Product $product, Request $request): void
    {
        if (!$request->has('attributes')) {
            return;
        }

        $rows = collect($request->input('attributes', []))
            ->map(fn ($a) => [
                'label' => trim((string) ($a['label'] ?? '')),
                'value' => trim((string) ($a['value'] ?? '')),
            ])
            ->filter(fn ($a) => $a['label'] !== '' && $a['value'] !== '')
            ->values()
            ->map(fn ($a, $i) => [
                'label'      => mb_substr($a['label'], 0, 255),
                'value'      => mb_substr($a['value'], 0, 1000),
                'sort_order' => $i,
            ])
            ->all();

        $product->productAttributes()->delete();
        if ($rows) {
            $product->productAttributes()->createMany($rows);
        }
    }

    protected function updateProductDetails(Product $product, Request $request): void
    {
        if ($request->has('physical') && $product->type === 'physical') {
            $physical = $this->normalizePhysical($request->physical);
            if ($product->physical) {
                $product->physical->update($physical);
            } else {
                $product->physical()->create($physical);
            }
        }

        if ($request->has('digital') && $product->type === 'digital') {
            $digital = $this->prepareDigital($request->digital);
            if ($product->digital) {
                $product->digital->update($digital);
            } else {
                $product->digital()->create($digital);
            }
        }

        if ($request->has('service') && $product->type === 'service') {
            if ($product->service) {
                $product->service->update($request->service);
            } else {
                $product->service()->create($request->service);
            }
        }

        $this->syncAttributes($product, $request);
        $this->syncOptionsAndVariants($product, $request);
    }
}
