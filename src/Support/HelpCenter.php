<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JeffersonGoncalves\KnowledgeBase\Enums\TranslationStatus;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CollectionContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\TranslatableContract;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;

/**
 * The one read API public help pages use (see design D3).
 *
 * It is public-only by construction: every result goes through
 * {@see Visibility} and {@see TranslationResolver}, so it adds no visibility,
 * language, fallback or redirect rules of its own, only composition. Anything
 * that is not publicly visible is reported as not found, never partially.
 */
class HelpCenter
{
    public function __construct(
        protected TranslationResolver $resolver,
        protected KnowledgeBaseService $service,
    ) {}

    /**
     * The publicly visible category tree for a language, each node named in the
     * served language with its number of publicly visible articles.
     *
     * @return list<CategoryNode>
     */
    public function categories(string $locale): array
    {
        return $this->categoryIndex($locale)['roots'];
    }

    /**
     * A publicly visible category by slug, with its publicly visible children
     * and articles, each resolved for the language; or null.
     */
    public function category(string $slug, string $locale): ?CategoryPage
    {
        $resolved = $this->resolver->resolveCategoryBySlug($slug, $locale);

        if (! $resolved instanceof ResolvedTranslation
            || ! $resolved->model instanceof CategoryContract
            || ! $resolved->model instanceof TranslatableContract) {
            return null;
        }

        $category = $resolved->model;
        $index = $this->categoryIndex($locale);
        $children = isset($index['flat'][(int) $category->getKey()])
            ? $index['flat'][(int) $category->getKey()]->children
            : [];

        return new CategoryPage(
            category: $category,
            translation: $resolved->translation,
            locale: $resolved->locale,
            slug: $resolved->slug,
            name: (string) $resolved->translation->getAttribute('name'),
            redirectSlug: $resolved->slug !== $slug ? $resolved->slug : null,
            children: $children,
            articles: $this->publicArticlesOfCategory($category, $locale),
        );
    }

    /**
     * A publicly visible article by slug, with the served language, the
     * fallback flag, the redirect slug, the other readable languages, its
     * category breadcrumb and its publicly visible related articles; or null.
     */
    public function article(string $slug, string $locale): ?ArticlePage
    {
        $resolved = $this->resolver->resolveArticleBySlug($slug, $locale);

        if (! $resolved instanceof ResolvedTranslation
            || ! $resolved->model instanceof ArticleContract
            || ! $resolved->model instanceof TranslatableContract) {
            return null;
        }

        $article = $resolved->model;

        return new ArticlePage(
            article: $article,
            translation: $resolved->translation,
            locale: $resolved->locale,
            slug: $resolved->slug,
            isFallback: $resolved->locale !== $locale,
            redirectSlug: $resolved->slug !== $slug ? $resolved->slug : null,
            alternates: $this->alternates($article),
            breadcrumb: $this->breadcrumb($article, $locale),
            related: $this->related($article, $locale),
        );
    }

    /**
     * An active collection by slug, with its public articles in order; or null.
     */
    public function collection(string $slug, string $locale): ?CollectionPage
    {
        $resolved = $this->resolver->resolveCollectionBySlug($slug, $locale);

        if (! $resolved instanceof ResolvedTranslation
            || ! $resolved->model instanceof CollectionContract
            || ! $resolved->model instanceof TranslatableContract) {
            return null;
        }

        $collection = $resolved->model;

        return new CollectionPage(
            collection: $collection,
            translation: $resolved->translation,
            locale: $resolved->locale,
            slug: $resolved->slug,
            isFallback: $resolved->locale !== $locale,
            redirectSlug: $resolved->slug !== $slug ? $resolved->slug : null,
            articles: $collection->publicArticles($locale, $this->resolver),
        );
    }

    /**
     * Search one language through the package's language-aware, public-only
     * search. Each result reports the language it was served in through
     * {@see Article::$served_locale}.
     *
     * @return Collection<int, Article>
     */
    public function search(string $query, string $locale, ?int $limit = null): Collection
    {
        $options = ['locale' => $locale];

        if ($limit !== null) {
            $options['limit'] = $limit;
        }

        return $this->service->search($query, $options);
    }

    /**
     * The public category tree plus an index of every node by category id, so a
     * category page can reuse the same resolution and counts.
     *
     * @return array{roots: list<CategoryNode>, flat: array<int, CategoryNode>}
     */
    protected function categoryIndex(string $locale): array
    {
        $categoryClass = ModelResolver::category();

        /** @var EloquentCollection<int, Model> $categories */
        $categories = $categoryClass::query()
            ->whereIn('id', Visibility::publicCategoryIds())
            ->orderBy('sort_order')
            ->get();

        $counts = $this->publicArticleCounts($locale);

        /** @var array<int, ResolvedTranslation> $resolved */
        $resolved = [];
        /** @var array<int, int|null> $parentOf */
        $parentOf = [];

        foreach ($categories as $category) {
            if (! $category instanceof CategoryContract || ! $category instanceof TranslatableContract) {
                continue;
            }

            $item = $this->resolver->resolveCategory($category, $locale);

            if (! $item instanceof ResolvedTranslation) {
                continue;
            }

            $id = (int) $category->getKey();
            $resolved[$id] = $item;
            $parent = $category->getAttribute('parent_id');
            $parentOf[$id] = $parent === null ? null : (int) $parent;
        }

        $flat = [];

        foreach ($resolved as $id => $item) {
            $flat[$id] = new CategoryNode(
                category: $item->model,
                translation: $item->translation,
                locale: $item->locale,
                slug: $item->slug,
                name: (string) $item->translation->getAttribute('name'),
                articlesCount: $counts[$id] ?? 0,
            );
        }

        $roots = [];

        foreach ($flat as $id => $node) {
            $parent = $parentOf[$id] ?? null;

            if ($parent !== null && isset($flat[$parent])) {
                $flat[$parent]->children[] = $node;
            } else {
                $roots[] = $node;
            }
        }

        return ['roots' => $roots, 'flat' => $flat];
    }

    /**
     * The number of publicly visible articles directly in each category that a
     * reader of the language gets, matching what the category page lists.
     *
     * @return array<int, int>
     */
    protected function publicArticleCounts(string $locale): array
    {
        $articleClass = ModelResolver::article();

        $query = Visibility::applyPublic($articleClass::query());

        // Under hide, only articles with a published version in the language
        // are served (see TranslationResolver::resolveArticle()).
        if (Locales::missingTranslation() === Locales::MISSING_HIDE) {
            $query->whereHas('translations', fn ($q) => $q
                ->where('locale', $locale)
                ->where('status', TranslationStatus::Published));
        }

        $rows = $query
            ->selectRaw('category_id, COUNT(*) as aggregate')
            ->groupBy('category_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->getAttribute('category_id')] = (int) $row->getAttribute('aggregate');
        }

        return $counts;
    }

    /**
     * @param  Model&CategoryContract  $category
     * @return list<ResolvedTranslation>
     */
    protected function publicArticlesOfCategory(Model $category, string $locale): array
    {
        $articleClass = ModelResolver::article();

        $articles = Visibility::applyPublic(
            $articleClass::query()->where('category_id', $category->getKey())
        )
            ->orderByDesc('created_at')
            ->get();

        $resolved = [];

        foreach ($articles as $article) {
            if (! $article instanceof TranslatableContract) {
                continue;
            }

            $item = $this->resolver->resolveArticle($article, $locale);

            if ($item instanceof ResolvedTranslation) {
                $resolved[] = $item;
            }
        }

        return $resolved;
    }

    /**
     * The category path of an article, root first, resolved for the language.
     *
     * @param  Model&ArticleContract&TranslatableContract  $article
     * @return list<ResolvedTranslation>
     */
    protected function breadcrumb(Model $article, string $locale): array
    {
        $categoryClass = ModelResolver::category();

        $chain = [];
        $seen = [];

        $categoryId = $article->getAttribute('category_id');
        $category = $categoryId === null ? null : $categoryClass::query()->find($categoryId);

        while ($category instanceof CategoryContract && $category instanceof TranslatableContract) {
            $id = (int) $category->getKey();

            if (isset($seen[$id])) {
                break;
            }

            $seen[$id] = true;

            $item = $this->resolver->resolveCategory($category, $locale);

            if ($item instanceof ResolvedTranslation) {
                array_unshift($chain, $item);
            }

            $parent = $category->getAttribute('parent_id');
            $category = $parent === null ? null : $categoryClass::query()->find($parent);
        }

        return $chain;
    }

    /**
     * The languages an article can be read in, mapped to that version's slug.
     * Only versions actually in the language are listed: a fallback result (the
     * served language differs from the requested one) is not.
     *
     * @param  Model&ArticleContract&TranslatableContract  $article
     * @return array<string, string>
     */
    protected function alternates(Model $article): array
    {
        $alternates = [];

        foreach (Locales::all() as $candidate) {
            $item = $this->resolver->resolveArticle($article, $candidate);

            if ($item instanceof ResolvedTranslation && $item->locale === $candidate) {
                $alternates[$candidate] = $item->slug;
            }
        }

        return $alternates;
    }

    /**
     * @param  Model&ArticleContract&TranslatableContract  $article
     * @return list<ResolvedTranslation>
     */
    protected function related(Model $article, string $locale): array
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        $relation = $article->relatedArticles();

        // Read the related articles (not the pivot) so the ambiguous `id` column
        // of the joined pivot table never overwrites the article's own id.
        $articles = Visibility::applyPublic($relation->getQuery())
            ->select($relation->getRelated()->getTable().'.*')
            ->orderBy($prefix.'article_relations.sort_order')
            ->get();

        $related = [];

        foreach ($articles as $item) {
            if (! $item instanceof ArticleContract || ! $item instanceof TranslatableContract) {
                continue;
            }

            $resolved = $this->resolver->resolveArticle($item, $locale);

            if ($resolved instanceof ResolvedTranslation) {
                $related[] = $resolved;
            }
        }

        return $related;
    }
}
