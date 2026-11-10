<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;
use JeffersonGoncalves\KnowledgeBase\Enums\TranslationStatus;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CollectionContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\TranslatableContract;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;

/**
 * The one read API help pages use (see design D3).
 *
 * Every method serves one audience: the public by default, or whatever the
 * app passes in (for example ArticleVisibility::Customers once it knows the
 * reader is a signed-in customer; the package never checks authentication
 * itself). Every result goes through {@see Visibility} and
 * {@see TranslationResolver}, so it adds no visibility, language, fallback or
 * redirect rules of its own, only composition. Anything the audience may not
 * read is reported as not found, never partially.
 */
class HelpCenter
{
    public function __construct(
        protected TranslationResolver $resolver,
        protected KnowledgeBaseService $service,
    ) {}

    /**
     * The category tree the audience may read in a language, each node named in
     * the served language with its number of articles the audience may read.
     *
     * @return list<CategoryNode>
     */
    public function categories(string $locale, ArticleVisibility $audience = ArticleVisibility::Public): array
    {
        return $this->categoryIndex($locale, $audience)['roots'];
    }

    /**
     * A category by slug, with its children and articles the audience may
     * read, each resolved for the language; or null.
     */
    public function category(string $slug, string $locale, ArticleVisibility $audience = ArticleVisibility::Public): ?CategoryPage
    {
        $resolved = $this->resolver->resolveCategoryBySlug($slug, $locale, audience: $audience);

        if (! $resolved instanceof ResolvedTranslation
            || ! $resolved->model instanceof CategoryContract
            || ! $resolved->model instanceof TranslatableContract) {
            return null;
        }

        $category = $resolved->model;
        $index = $this->categoryIndex($locale, $audience);
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
            articles: $this->articlesOfCategory($category, $locale, $audience),
        );
    }

    /**
     * An article by slug the audience may read, with the served language, the
     * fallback flag, the redirect slug, the other readable languages, its
     * category breadcrumb and its related articles the audience may read; or
     * null.
     */
    public function article(string $slug, string $locale, ArticleVisibility $audience = ArticleVisibility::Public): ?ArticlePage
    {
        $resolved = $this->resolver->resolveArticleBySlug($slug, $locale, audience: $audience);

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
            alternates: $this->alternates($article, $audience),
            breadcrumb: $this->breadcrumb($article, $locale, $audience),
            related: $this->related($article, $locale, $audience),
        );
    }

    /**
     * An active collection by slug, with the articles the audience may read in
     * order; or null.
     */
    public function collection(string $slug, string $locale, ArticleVisibility $audience = ArticleVisibility::Public): ?CollectionPage
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
            articles: $audience === ArticleVisibility::Public
                ? $collection->publicArticles($locale, $this->resolver)
                : $this->articlesOfCollection($collection, $locale, $audience),
        );
    }

    /**
     * Search one language through the package's language-aware search, limited
     * to what the audience may read. Each result reports the language it was
     * served in through {@see Article::$served_locale}.
     *
     * @return Collection<int, Article>
     */
    public function search(string $query, string $locale, ?int $limit = null, ArticleVisibility $audience = ArticleVisibility::Public): Collection
    {
        $options = ['locale' => $locale, 'audience' => $audience];

        if ($limit !== null) {
            $options['limit'] = $limit;
        }

        return $this->service->search($query, $options);
    }

    /**
     * The category tree the audience may read plus an index of every node by
     * category id, so a category page can reuse the same resolution and counts.
     *
     * @return array{roots: list<CategoryNode>, flat: array<int, CategoryNode>}
     */
    protected function categoryIndex(string $locale, ArticleVisibility $audience): array
    {
        $categoryClass = ModelResolver::category();

        /** @var EloquentCollection<int, Model> $categories */
        $categories = $categoryClass::query()
            ->whereIn('id', Visibility::categoryIdsFor($audience))
            ->orderBy('sort_order')
            ->get();

        $counts = $this->articleCounts($locale, $audience);

        /** @var array<int, ResolvedTranslation> $resolved */
        $resolved = [];
        /** @var array<int, int|null> $parentOf */
        $parentOf = [];

        foreach ($categories as $category) {
            if (! $category instanceof CategoryContract || ! $category instanceof TranslatableContract) {
                continue;
            }

            $item = $this->resolver->resolveCategory($category, $locale, audience: $audience);

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
     * The number of articles directly in each category that the audience may
     * read and a reader of the language gets, matching what the category page
     * lists.
     *
     * @return array<int, int>
     */
    protected function articleCounts(string $locale, ArticleVisibility $audience): array
    {
        $articleClass = ModelResolver::article();

        $query = Visibility::applyFor($articleClass::query(), $audience);

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
    protected function articlesOfCategory(Model $category, string $locale, ArticleVisibility $audience): array
    {
        $articleClass = ModelResolver::article();

        $articles = Visibility::applyFor(
            $articleClass::query()->where('category_id', $category->getKey()),
            $audience,
        )
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get();

        return $this->resolveArticles($articles, $locale, $audience);
    }

    /**
     * The collection's articles a non-public audience may read, in the
     * collection's order. The public audience goes through
     * CollectionContract::publicArticles(), so a custom collection model keeps
     * control of the public view.
     *
     * @param  Model&CollectionContract  $collection
     * @return list<ResolvedTranslation>
     */
    protected function articlesOfCollection(Model $collection, string $locale, ArticleVisibility $audience): array
    {
        $relation = $collection->articles();

        // Read the articles (not the pivot) so the ambiguous `id` column of the
        // joined pivot table never overwrites the article's own id.
        $articles = Visibility::applyFor($relation->getQuery(), $audience)
            ->select($relation->getRelated()->getTable().'.*')
            ->get();

        return $this->resolveArticles($articles, $locale, $audience);
    }

    /**
     * The category path of an article, root first, resolved for the language.
     *
     * @param  Model&ArticleContract&TranslatableContract  $article
     * @return list<ResolvedTranslation>
     */
    protected function breadcrumb(Model $article, string $locale, ArticleVisibility $audience): array
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

            $item = $this->resolver->resolveCategory($category, $locale, audience: $audience);

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
    protected function alternates(Model $article, ArticleVisibility $audience): array
    {
        $alternates = [];

        foreach (Locales::all() as $candidate) {
            $item = $this->resolver->resolveArticle($article, $candidate, audience: $audience);

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
    protected function related(Model $article, string $locale, ArticleVisibility $audience): array
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        $relation = $article->relatedArticles();

        // Read the related articles (not the pivot) so the ambiguous `id` column
        // of the joined pivot table never overwrites the article's own id.
        $articles = Visibility::applyFor($relation->getQuery(), $audience)
            ->select($relation->getRelated()->getTable().'.*')
            ->orderBy($prefix.'article_relations.sort_order')
            ->get();

        return $this->resolveArticles($articles, $locale, $audience);
    }

    /**
     * Resolve each article for the language and audience, leaving out the
     * ones nothing is served for.
     *
     * @param  iterable<mixed>  $articles
     * @return list<ResolvedTranslation>
     */
    protected function resolveArticles(iterable $articles, string $locale, ArticleVisibility $audience): array
    {
        $resolved = [];

        foreach ($articles as $article) {
            if (! $article instanceof Model || ! $article instanceof ArticleContract || ! $article instanceof TranslatableContract) {
                continue;
            }

            $item = $this->resolver->resolveArticle($article, $locale, audience: $audience);

            if ($item instanceof ResolvedTranslation) {
                $resolved[] = $item;
            }
        }

        return $resolved;
    }
}
