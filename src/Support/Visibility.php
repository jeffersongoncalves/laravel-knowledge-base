<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;

/**
 * The single definition of what may be shown to whom.
 *
 * Readers come in audiences: the public (everyone, including search engines),
 * signed-in customers and staff. An article is visible to an audience only
 * when it is published, its visibility is one the audience may read (see
 * {@see self::levels()}), and its category and every ancestor category exist
 * (are not deleted), are active and carry a visibility the audience may read,
 * so an article is never more visible than its category. Anything that cannot
 * be resolved (missing parent, a parent cycle) fails closed. Who counts as a
 * customer is the app's decision: callers pass the audience in.
 */
class Visibility
{
    /**
     * The visibility values an audience may read.
     *
     * @return list<string>
     */
    public static function levels(ArticleVisibility $audience): array
    {
        return match ($audience) {
            ArticleVisibility::Public => [ArticleVisibility::Public->value],
            ArticleVisibility::Customers => [ArticleVisibility::Public->value, ArticleVisibility::Customers->value],
            ArticleVisibility::Internal => array_map(fn (ArticleVisibility $case) => $case->value, ArticleVisibility::cases()),
        };
    }

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
        return static::applyFor($query, ArticleVisibility::Public);
    }

    /**
     * Limit an article query to the articles signed-in customers may read:
     * public and customers articles in categories they may read.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyCustomer(Builder $query): Builder
    {
        return static::applyFor($query, ArticleVisibility::Customers);
    }

    /**
     * Limit an article query to the articles an audience may read.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyFor(Builder $query, ArticleVisibility $audience): Builder
    {
        return $query
            ->where($query->qualifyColumn('status'), ArticleStatus::Published)
            ->whereIn($query->qualifyColumn('visibility'), static::levels($audience))
            ->whereIn($query->qualifyColumn('category_id'), static::categoryIdsFor($audience));
    }

    /**
     * Limit an article query to published articles that are not publicly
     * visible (customers and internal ones included). Together with
     * applyPublic() this covers every published article exactly once.
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
     * Whether a single article may be shown to the public. The PHP-side twin of
     * applyPublic(), so lookups that already hold a model reuse the same rule.
     */
    public static function articleIsPubliclyVisible(Model $article): bool
    {
        return static::articleIsVisibleTo($article, ArticleVisibility::Public);
    }

    /**
     * Whether a single article may be shown to an audience. The PHP-side twin
     * of applyFor().
     */
    public static function articleIsVisibleTo(Model $article, ArticleVisibility $audience): bool
    {
        $status = $article->getAttribute('status');
        $published = $status instanceof ArticleStatus
            ? $status === ArticleStatus::Published
            : $status === ArticleStatus::Published->value;

        if (! $published) {
            return false;
        }

        $visibility = $article->getAttribute('visibility');
        $value = $visibility instanceof ArticleVisibility ? $visibility->value : $visibility;

        if (! in_array($value, static::levels($audience), true)) {
            return false;
        }

        return in_array((int) $article->getAttribute('category_id'), static::categoryIdsFor($audience), true);
    }

    /**
     * Whether a single category may be shown to the public. The PHP-side twin
     * of Category::scopePubliclyVisible(). A soft-deleted category is never
     * public, because publicCategoryIds() leaves it out.
     */
    public static function categoryIsPubliclyVisible(Model $category): bool
    {
        return static::categoryIsVisibleTo($category, ArticleVisibility::Public);
    }

    /**
     * Whether a single category may be shown to an audience.
     */
    public static function categoryIsVisibleTo(Model $category, ArticleVisibility $audience): bool
    {
        return in_array((int) $category->getKey(), static::categoryIdsFor($audience), true);
    }

    /**
     * Ids of the categories that are active, public and not deleted, and whose
     * ancestors are all likewise.
     *
     * @return list<int>
     */
    public static function publicCategoryIds(): array
    {
        return static::categoryIdsFor(ArticleVisibility::Public);
    }

    /**
     * Ids of the categories that are active, not deleted and carry a
     * visibility the audience may read, and whose ancestors are all likewise.
     *
     * Resolved in PHP from a single query instead of a recursive CTE, so it
     * works the same on every supported database driver.
     *
     * @return list<int>
     */
    public static function categoryIdsFor(ArticleVisibility $audience): array
    {
        $levels = static::levels($audience);
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

                if (! in_array($row->visibility, $levels, true)
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
            // reached a readable root or passed through the failing ancestor.
            foreach (array_keys($chain) as $walked) {
                $resolved[$walked] = $visible;
            }
        }

        return array_map('intval', array_keys(array_filter($resolved)));
    }
}
