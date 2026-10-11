<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;

/**
 * The single definition of what may be shown to customers.
 *
 * An article is publicly visible only when it is published, its visibility is
 * public, and its category and every ancestor category exist (are not
 * deleted), are active and are public. Every other published article is
 * staff-only. Anything that cannot be resolved (missing parent, a parent
 * cycle) fails closed and counts as not publicly visible.
 */
class Visibility
{
    /**
     * Limit an article query to publicly visible articles.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyPublic(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('status'), ArticleStatus::Published)
            ->where($query->qualifyColumn('visibility'), ArticleVisibility::Public)
            ->whereIn($query->qualifyColumn('category_id'), static::publicCategoryIds());
    }

    /**
     * Limit an article query to published articles that are not publicly
     * visible. Together with applyPublic() this covers every published
     * article exactly once.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyStaffOnly(Builder $query): Builder
    {
        $categoryIds = static::publicCategoryIds();

        return $query
            ->where($query->qualifyColumn('status'), ArticleStatus::Published)
            ->where(function (Builder $q) use ($query, $categoryIds) {
                $q->where($query->qualifyColumn('visibility'), '!=', ArticleVisibility::Public)
                    ->orWhereNotIn($query->qualifyColumn('category_id'), $categoryIds);
            });
    }

    /**
     * Ids of the categories that are active, public and not deleted, and whose
     * ancestors are all likewise.
     *
     * Resolved in PHP from a single query instead of a recursive CTE, so it
     * works the same on every supported database driver.
     *
     * @return list<int>
     */
    public static function publicCategoryIds(): array
    {
        $categoryClass = ModelResolver::category();
        $query = $categoryClass::query();
        $columns = ['id', 'parent_id', 'visibility', 'is_active'];
        $deletedAt = null;

        if (in_array(SoftDeletes::class, class_uses_recursive($categoryClass), true)) {
            // @phpstan-ignore method.notFound (withTrashed() is provided by the SoftDeletes trait)
            $query->withTrashed();
            // @phpstan-ignore method.notFound (getDeletedAtColumn() is provided by the SoftDeletes trait)
            $deletedAt = $query->getModel()->getDeletedAtColumn();
            $columns[] = $deletedAt;
        }

        // Raw rows (no casts), so a custom model casting visibility to an enum
        // is compared the same way as the default string column.
        $rows = $query->toBase()->get($columns)->keyBy('id');

        /** @var array<int|string, bool> $resolved */
        $resolved = [];

        foreach ($rows->keys() as $id) {
            $chain = [];
            $current = $id;

            while (true) {
                if (isset($resolved[$current])) {
                    $visible = $resolved[$current];
                    break;
                }

                $row = $rows->get($current);

                // Missing parent or a cycle back into the chain: fail closed.
                if ($row === null || isset($chain[$current])) {
                    $visible = false;
                    break;
                }

                $chain[$current] = true;

                if ($row->visibility !== ArticleVisibility::Public->value
                    || ! (bool) $row->is_active
                    || ($deletedAt !== null && $row->{$deletedAt} !== null)) {
                    $visible = false;
                    break;
                }

                if ($row->parent_id === null) {
                    $visible = true;
                    break;
                }

                $current = $row->parent_id;
            }

            // Every category on the walked chain shares the outcome: it either
            // reached a public root or passed through the failing ancestor.
            foreach (array_keys($chain) as $walked) {
                $resolved[$walked] = $visible;
            }
        }

        return array_map('intval', array_keys(array_filter($resolved)));
    }
}
