<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Model;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\TranslationStatus;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CollectionContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\TranslatableContract;

/**
 * One resolver for "what does a reader get" (see design D3).
 *
 * It applies the article-governs publishing rule (D2), the public/staff
 * visibility rule (reused from {@see Visibility}) and the configured
 * missing-language behaviour (D7), and reports which language was actually
 * served together with that version's slug.
 */
class TranslationResolver
{
    /**
     * Resolve an article for a requested locale.
     */
    public function resolveArticle(Model&ArticleContract&TranslatableContract $article, string $locale, bool $publicOnly = true): ?ResolvedTranslation
    {
        if (! $this->articleReadable($article, $publicOnly)) {
            return null;
        }

        $requested = $article->translation($locale);

        if ($requested instanceof Model && $this->translationReadable($requested)) {
            return new ResolvedTranslation($article, $requested, $locale, (string) $requested->getAttribute('slug'));
        }

        if (Locales::missingTranslation() === Locales::MISSING_HIDE) {
            return null;
        }

        $authoritative = $article->authoritativeTranslation();

        if ($authoritative instanceof Model && $this->translationReadable($authoritative)) {
            $authoritativeLocale = (string) $article->getAttribute('source_locale');

            return new ResolvedTranslation(
                $article,
                $authoritative,
                $authoritativeLocale,
                (string) $authoritative->getAttribute('slug'),
            );
        }

        return null;
    }

    /**
     * Find the one article that owns a slug (any language, see design D1a) and
     * resolve it for the requested locale.
     */
    public function resolveArticleBySlug(string $slug, string $locale, bool $publicOnly = true): ?ResolvedTranslation
    {
        $translationClass = ModelResolver::articleTranslation();

        $translation = $translationClass::query()->where('slug', $slug)->first();

        if (! $translation instanceof Model) {
            return null;
        }

        $article = $translation->article()->first();

        if (! $article instanceof Model || ! $article instanceof ArticleContract || ! $article instanceof TranslatableContract) {
            return null;
        }

        return $this->resolveArticle($article, $locale, $publicOnly);
    }

    /**
     * Resolve a category for a requested locale.
     */
    public function resolveCategory(Model&CategoryContract&TranslatableContract $category, string $locale, bool $publicOnly = true): ?ResolvedTranslation
    {
        if ($publicOnly && ! Visibility::categoryIsPubliclyVisible($category)) {
            return null;
        }

        $requested = $category->translation($locale);

        if ($requested instanceof Model) {
            return new ResolvedTranslation($category, $requested, $locale, (string) $requested->getAttribute('slug'));
        }

        if (Locales::missingTranslation() === Locales::MISSING_HIDE) {
            return null;
        }

        $default = $category->authoritativeTranslation();

        if ($default instanceof Model) {
            return new ResolvedTranslation(
                $category,
                $default,
                Locales::default(),
                (string) $default->getAttribute('slug'),
            );
        }

        return null;
    }

    /**
     * Find the one category that owns a slug (any language) and resolve it for
     * the requested locale.
     */
    public function resolveCategoryBySlug(string $slug, string $locale, bool $publicOnly = true): ?ResolvedTranslation
    {
        $translationClass = ModelResolver::categoryTranslation();

        $translation = $translationClass::query()->where('slug', $slug)->first();

        if (! $translation instanceof Model) {
            return null;
        }

        $category = $translation->category()->first();

        if (! $category instanceof Model || ! $category instanceof CategoryContract || ! $category instanceof TranslatableContract) {
            return null;
        }

        return $this->resolveCategory($category, $locale, $publicOnly);
    }

    /**
     * Resolve a collection for a requested locale (see design D1 and D2).
     *
     * A collection is customer-visible only when it is active; an inactive or
     * deleted collection resolves to nothing. Like categories, a collection has
     * no per-language status, so the requested version is served when it exists
     * and the authoritative version otherwise (or nothing under 'hide').
     */
    public function resolveCollection(Model&CollectionContract&TranslatableContract $collection, string $locale, bool $publicOnly = true): ?ResolvedTranslation
    {
        if ($publicOnly && ! (bool) $collection->getAttribute('is_active')) {
            return null;
        }

        $requested = $collection->translation($locale);

        if ($requested instanceof Model) {
            return new ResolvedTranslation($collection, $requested, $locale, (string) $requested->getAttribute('slug'));
        }

        if (Locales::missingTranslation() === Locales::MISSING_HIDE) {
            return null;
        }

        $default = $collection->authoritativeTranslation();

        if ($default instanceof Model) {
            return new ResolvedTranslation(
                $collection,
                $default,
                Locales::default(),
                (string) $default->getAttribute('slug'),
            );
        }

        return null;
    }

    /**
     * Find the one collection that owns a slug (any language) and resolve it for
     * the requested locale.
     */
    public function resolveCollectionBySlug(string $slug, string $locale, bool $publicOnly = true): ?ResolvedTranslation
    {
        $translationClass = ModelResolver::collectionTranslation();

        $translation = $translationClass::query()->where('slug', $slug)->first();

        if (! $translation instanceof Model) {
            return null;
        }

        $collection = $translation->collection()->first();

        if (! $collection instanceof Model || ! $collection instanceof CollectionContract || ! $collection instanceof TranslatableContract) {
            return null;
        }

        return $this->resolveCollection($collection, $locale, $publicOnly);
    }

    protected function articleReadable(Model&ArticleContract $article, bool $publicOnly): bool
    {
        $status = $article->getAttribute('status');
        $published = $status instanceof ArticleStatus
            ? $status === ArticleStatus::Published
            : $status === ArticleStatus::Published->value;

        if (! $published) {
            return false;
        }

        return ! $publicOnly || Visibility::articleIsPubliclyVisible($article);
    }

    protected function translationReadable(Model $translation): bool
    {
        $status = $translation->getAttribute('status');

        return $status instanceof TranslationStatus
            ? $status === TranslationStatus::Published
            : $status === TranslationStatus::Published->value;
    }
}
