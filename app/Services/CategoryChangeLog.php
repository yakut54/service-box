<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryChange;
use Illuminate\Http\Request;

/**
 * Журнал ручных правок категории — владелец/администратор создал,
 * переименовал, переместил, скрыл или удалил категорию (см.
 * CategoryController::store/update/destroy). Та же идея, что у
 * ProductChangeLog, для дерева категорий.
 */
class CategoryChangeLog
{
    /**
     * Поля категории, за которыми следим — используется и здесь, и в
     * CategoryController::update() (снимок ДО через Category::only(TRACKED_FIELDS)).
     * slug/sort_order сознательно не отслеживаются: slug — техническое
     * производное от названия, sort_order меняется пачкой через reorder()
     * и был бы слишком шумным (та же логика, что у ProductChangeLog).
     */
    public const TRACKED_FIELDS = ['name', 'parent_id', 'description', 'image_url', 'is_visible', 'age_restricted', 'no_return'];

    public static function recordCategoryCreate(Category $category, Request $request): void
    {
        self::persist([self::event($category, 'created')], $request);
    }

    /**
     * @param array<string,mixed> $oldAttrs только ключи из TRACKED_FIELDS,
     *        полученные через Category::only() ДО Category::update().
     */
    public static function recordCategoryUpdate(Category $category, array $oldAttrs, Request $request): void
    {
        self::persist(self::attributeRows($category, $oldAttrs), $request);
    }

    public static function recordCategoryDelete(Category $category, Request $request): void
    {
        self::persist([self::event($category, 'deleted')], $request);
    }

    private static function persist(array $rows, Request $request): void
    {
        $staffRole = $request->attributes->get('staff_role');
        if (empty($rows) || !in_array($staffRole, ['owner', 'admin'], true)) {
            return;
        }

        $user = $request->user();

        foreach ($rows as &$row) {
            $row['actor_user_id'] = $user->id;
            $row['actor_name']    = $user->name;
            $row['actor_role']    = $staffRole;
        }
        unset($row);

        foreach ($rows as $row) {
            CategoryChange::create($row);
        }

        $shop = $request->attributes->get('shop');
        if ($shop) {
            \App\Events\ShopChannelEvent::dispatch($shop->id, 'category_changes.updated');
        }
    }

    private static function event(Category $category, string $action): array
    {
        return [
            'category_id'   => $category->id,
            'category_name' => $category->name,
            'action'        => $action,
            'field'         => null,
            'old_value'     => null,
            'new_value'     => null,
        ];
    }

    private static function row(Category $category, string $field, mixed $old, mixed $new): array
    {
        return [
            'category_id'   => $category->id,
            'category_name' => $category->name,
            'action'        => 'updated',
            'field'         => $field,
            'old_value'     => $old === null ? null : (string) $old,
            'new_value'     => $new === null ? null : (string) $new,
        ];
    }

    /**
     * @param array<string,mixed> $oldAttrs
     */
    private static function attributeRows(Category $category, array $oldAttrs): array
    {
        $rows = [];

        if (array_key_exists('name', $oldAttrs) && $oldAttrs['name'] !== $category->name) {
            $rows[] = self::row($category, 'name', $oldAttrs['name'], $category->name);
        }

        if (array_key_exists('parent_id', $oldAttrs) && $oldAttrs['parent_id'] !== $category->parent_id) {
            $rows[] = self::row(
                $category, 'parent_id',
                self::categoryName($oldAttrs['parent_id']),
                self::categoryName($category->parent_id),
            );
        }

        if (array_key_exists('description', $oldAttrs) && $oldAttrs['description'] !== $category->description) {
            $rows[] = self::row($category, 'description', $oldAttrs['description'], $category->description);
        }

        if (array_key_exists('image_url', $oldAttrs) && $oldAttrs['image_url'] !== $category->image_url) {
            $rows[] = self::row($category, 'image_url', $oldAttrs['image_url'], $category->image_url);
        }

        if (array_key_exists('is_visible', $oldAttrs) && $oldAttrs['is_visible'] !== $category->is_visible) {
            $rows[] = self::row($category, 'visible', $oldAttrs['is_visible'] ? '1' : '0', $category->is_visible ? '1' : '0');
        }

        if (array_key_exists('age_restricted', $oldAttrs) && $oldAttrs['age_restricted'] !== $category->age_restricted) {
            $rows[] = self::row($category, 'age_restricted', $oldAttrs['age_restricted'] ? '1' : '0', $category->age_restricted ? '1' : '0');
        }

        if (array_key_exists('no_return', $oldAttrs) && $oldAttrs['no_return'] !== $category->no_return) {
            $rows[] = self::row($category, 'no_return', $oldAttrs['no_return'] ? '1' : '0', $category->no_return ? '1' : '0');
        }

        return $rows;
    }

    /** Снапшот ИМЕНИ родителя, не UUID — читаемо, и переживает переименование/удаление категории позже. */
    private static function categoryName(?string $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }
        return Category::withTrashed()->find($categoryId)?->name ?? '—';
    }
}
