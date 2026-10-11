<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The one-article-per-slug rule (see design D1a): a slug may be reused by the
 * versions of one article/category, but never by another one, in any language.
 *
 * Shared by the translation models' save guard and the main-row mirror, so the
 * rule holds whichever side is written.
 */
class SlugOwnership
{
    /**
     * Whether the slug already belongs to a different article/category.
     *
     * @param  class-string<Model>  $translationClass
     */
    public static function isTakenByAnother(string $translationClass, string $foreignKey, int|string|null $ownerId, string $slug): bool
    {
        // A row that is not saved yet owns nothing, so any existing use of the
        // slug belongs to another record. (`!= NULL` would match no rows.)
        return $translationClass::query()
            ->where('slug', $slug)
            ->when($ownerId !== null, fn ($query) => $query->where($foreignKey, '!=', $ownerId))
            ->exists();
    }
}
