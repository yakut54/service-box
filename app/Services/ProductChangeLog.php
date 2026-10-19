<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductChange;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

/**
 * Журнал РУЧНЫХ правок товара — владелец/администратор что-то поменял в
 * карточке товара (см. ProductController::store/update/destroy). Не пишет
 * сюда: PhysicalStockService (списание/возврат при заказах) и
 * OrderReweighService (списание при взвешивании) — это следствие заказа, не
 * правка человека. Сборщик/мастер сюда не попадают вообще — у них нет
 * доступа к товарам.
 *
 * Было (прошлая сессия) — только остаток (`StockJournal`, таблица
 * `stock_adjustments`). Расширено на цену/старую цену/название/категорию/
 * видимость + события «создан»/«удалён» целиком — остаток был слишком узким
 * срезом «любого редактирования», которое с самого начала просили фиксировать
 * (найдено живым тестом 2026-10-16: правка цены никак не попадала в журнал).
 */
class ProductChangeLog
{
    /**
     * Поля продукта, не связанные со складом, за которыми следим —
     * используется и здесь, и в ProductController::update() (снимок ДО
     * через Product::only(self::TRACKED_FIELDS)), чтобы список не дублировался.
     */
    public const TRACKED_FIELDS = ['name', 'description', 'image_url', 'price', 'compare_price', 'is_active', 'category_id'];

    public static function recordProductCreate(Product $product, Request $request): void
    {
        $rows = [self::event($product, 'created')];
        array_push($rows, ...self::stockRows($product, null));
        self::persist($rows, $request, 'Товар создан');
    }

    /**
     * @param array{sale_mode:string, simple:int, weight:int, variants:array<string,int>} $oldStock
     *        снимок ДО ProductController::updateProductDetails()
     * @param array<string,mixed> $oldAttrs снимок name/price/compare_price/
     *        is_active/category_id ДО ProductController::update()'s
     *        $product->update(...) — через $product->only(TRACKED_FIELDS)
     */
    public static function recordProductUpdate(Product $product, array $oldStock, array $oldAttrs, Request $request): void
    {
        $rows = [...self::stockRows($product, $oldStock), ...self::attributeRows($product, $oldAttrs)];
        self::persist($rows, $request, null);
    }

    public static function recordProductDelete(Product $product, Request $request): void
    {
        self::persist([self::event($product, 'deleted')], $request, null);
    }

    private static function persist(array $rows, Request $request, ?string $defaultReason): void
    {
        $staffRole = $request->attributes->get('staff_role');
        if (empty($rows) || !in_array($staffRole, ['owner', 'admin'], true)) {
            return;
        }

        $reason = $defaultReason ?? (trim((string) $request->input('change_reason', '')) ?: null);
        $user = $request->user();

        foreach ($rows as &$row) {
            $row['actor_user_id'] = $user->id;
            $row['actor_name']    = $user->name;
            $row['actor_role']    = $staffRole;
            $row['reason']        = $reason;
        }
        unset($row);

        // create() в цикле, не insert($rows) — иначе Eloquent-события
        // created не летят, а на них держится серый бейдж "всего" в
        // AppServiceProvider::registerNavCountsBroadcasts(). Строк за раз
        // мало (1-5), так что цена цикла не важна.
        foreach ($rows as $row) {
            ProductChange::create($row);
        }

        $shop = $request->attributes->get('shop');
        if ($shop) {
            \App\Events\ShopChannelEvent::dispatch($shop->id, 'product_changes.updated');
        }
    }

    /** @return array<string,mixed> строка «товар создан»/«товар удалён» — событие целиком, без конкретного поля. */
    private static function event(Product $product, string $action): array
    {
        return [
            'product_id'    => $product->id,
            'variant_id'    => null,
            'product_name'  => $product->name,
            'variant_label' => null,
            'action'        => $action,
            'field'         => null,
            'unit'          => null,
            'old_value'     => null,
            'new_value'     => null,
        ];
    }

    private static function row(Product $product, ?ProductVariant $variant, string $field, ?string $unit, mixed $old, mixed $new): array
    {
        return [
            'product_id'    => $product->id,
            'variant_id'    => $variant?->id,
            'product_name'  => $product->name,
            'variant_label' => $variant?->shortLabel(),
            'action'        => 'updated',
            'field'         => $field,
            'unit'          => $unit,
            'old_value'     => $old === null ? null : (string) $old,
            'new_value'     => $new === null ? null : (string) $new,
        ];
    }

    /**
     * @param array<string,mixed> $oldAttrs только ключи из TRACKED_FIELDS,
     *        полученные через Product::only() — те, что реально были в
     *        снимке, сравниваем; остальные игнорируем (их не трогали).
     */
    private static function attributeRows(Product $product, array $oldAttrs): array
    {
        $rows = [];

        if (array_key_exists('name', $oldAttrs) && $oldAttrs['name'] !== $product->name) {
            $rows[] = self::row($product, null, 'name', null, $oldAttrs['name'], $product->name);
        }

        if (array_key_exists('description', $oldAttrs) && $oldAttrs['description'] !== $product->description) {
            $rows[] = self::row($product, null, 'description', null, $oldAttrs['description'], $product->description);
        }

        if (array_key_exists('image_url', $oldAttrs) && $oldAttrs['image_url'] !== $product->image_url) {
            $rows[] = self::row($product, null, 'image_url', null, $oldAttrs['image_url'], $product->image_url);
        }

        if (array_key_exists('price', $oldAttrs) && $oldAttrs['price'] !== $product->price) {
            $rows[] = self::row($product, null, 'price', null, $oldAttrs['price'], $product->price);
        }

        if (array_key_exists('compare_price', $oldAttrs) && $oldAttrs['compare_price'] !== $product->compare_price) {
            $rows[] = self::row($product, null, 'compare_price', null, $oldAttrs['compare_price'], $product->compare_price);
        }

        if (array_key_exists('is_active', $oldAttrs) && $oldAttrs['is_active'] !== $product->is_active) {
            $rows[] = self::row($product, null, 'active', null, $oldAttrs['is_active'] ? '1' : '0', $product->is_active ? '1' : '0');
        }

        if (array_key_exists('category_id', $oldAttrs) && $oldAttrs['category_id'] !== $product->category_id) {
            $rows[] = self::row(
                $product, null, 'category', null,
                self::categoryName($oldAttrs['category_id']),
                self::categoryName($product->category_id),
            );
        }

        return $rows;
    }

    /** Снапшот ИМЕНИ категории, не UUID — читаемо, и переживает переименование/удаление категории позже. */
    private static function categoryName(?string $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }
        return Category::withTrashed()->find($categoryId)?->name ?? '—';
    }

    /**
     * @param array{sale_mode:string, simple:int, weight:int, variants:array<string,int>}|null $oldStock
     *        null — создание товара, сравнение безусловно с нулём.
     * @return list<array<string,mixed>>
     */
    private static function stockRows(Product $product, ?array $oldStock): array
    {
        $rows = [];
        $isNew = $oldStock === null;

        // Варианты существуют только у штучного товара (см.
        // ProductController::syncOptionsAndVariants) — остаток тогда ведётся
        // per-вариант, а не в products_physical.
        if ($product->options()->exists()) {
            foreach ($product->variants()->get(['id', 'stock_quantity']) as $v) {
                $old = $isNew ? 0 : ($oldStock['variants'][$v->id] ?? null);
                if ($old !== null && $old !== $v->stock_quantity) {
                    $rows[] = self::row($product, $v, 'stock', 'pcs', $old, $v->stock_quantity);
                }
            }
            return $rows;
        }

        $saleMode = $product->physical()->value('sale_mode') ?? 'piece';

        // Сравнивать значение между режимами бессмысленно — поле меняется
        // не потому, что кто-то поправил остаток, а потому что поменялся
        // режим продажи (см. ProductController::normalizePhysical).
        if (!$isNew && $oldStock['sale_mode'] !== $saleMode) {
            return $rows;
        }

        [$unit, $new] = $saleMode === 'piece'
            ? ['pcs', $product->physical()->value('stock_quantity') ?? 0]
            : ['g',   $product->physical()->value('stock_weight_grams') ?? 0];
        $old = $isNew ? 0 : ($saleMode === 'piece' ? $oldStock['simple'] : $oldStock['weight']);

        if ($old !== $new) {
            $rows[] = self::row($product, null, 'stock', $unit, $old, $new);
        }

        return $rows;
    }
}
