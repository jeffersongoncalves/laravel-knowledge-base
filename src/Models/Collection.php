<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JeffersonGoncalves\KnowledgeBase\Models\Concerns\HasTranslations;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CollectionContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\TranslatableContract;
use JeffersonGoncalves\KnowledgeBase\Support\Locales;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;
use JeffersonGoncalves\KnowledgeBase\Support\ResolvedTranslation;
use JeffersonGoncalves\KnowledgeBase\Support\TranslationResolver;
use JeffersonGoncalves\KnowledgeBase\Support\Visibility;

/**
 * A curated, ordered list of articles (see design D1), such as an FAQ.
 *
 * Like categories, a collection always has a version in the configured default
 * language, and the main row mirrors it. Articles may be any article, public or
 * not: the public read filters at read time (design D2), so an article whose
 * visibility changes later is handled without re-saving the collection.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read EloquentCollection<int, CollectionTranslation> $translations
 * @property-read EloquentCollection<int, Article> $articles
 */
class Collection extends Model implements CollectionContract, TranslatableContract
{
    use HasFactory;
    use HasTranslations;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getTable(): string
    {
        return (config('knowledge-base.table_prefix') ?? '').'collections';
    }

    /**
     * Collections always have a version in the configured default language
     * (see design D1), like categories.
     */
    public function authoritativeLocale(): string
    {
        return Locales::default();
    }

    /** @return class-string<Model> */
    protected function translationModel(): string
    {
        return ModelResolver::collectionTranslation();
    }

    protected function translationForeignKey(): string
    {
        return 'collection_id';
    }

    /** @return list<string> */
    protected function mirroredAttributes(): array
    {
        return ['name', 'slug', 'description'];
    }

    /**
     * The articles in the collection, ordered by their position on the pivot.
     */
    public function articles(): BelongsToMany
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        return $this->belongsToMany(
            ModelResolver::article(),
            $prefix.'collection_article',
            'collection_id',
            'article_id'
        )
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy($prefix.'collection_article.sort_order');
    }

    public function addArticle(int|Model $article, ?int $sortOrder = null): void
    {
        $id = $this->articleId($article);

        if ($this->articles()->whereKey($id)->exists()) {
            throw new InvalidArgumentException("Article [{$id}] is already in the collection.");
        }

        $this->articles()->attach($id, ['sort_order' => $sortOrder ?? $this->nextArticleOrder()]);
    }

    public function removeArticle(int|Model $article): void
    {
        $this->articles()->detach($this->articleId($article));
    }

    /**
     * @param  array<int, int|Model>  $articles
     */
    public function reorderArticles(array $articles): void
    {
        foreach (array_values($articles) as $position => $article) {
            $this->articles()->updateExistingPivot($this->articleId($article), ['sort_order' => $position]);
        }
    }

    /**
     * @return list<int>
     */
    public function articleIds(): array
    {
        $related = $this->articles()->getRelated();

        return $this->articles()
            ->pluck($related->getQualifiedKeyName())
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The public view of the collection (see design D2): its publicly visible
     * articles, in order, each resolved for the requested language. An article
     * whose requested-language version is not readable is left out under the
     * 'hide' setting and served in its authoritative language under 'fallback'.
     *
     * @return list<ResolvedTranslation>
     */
    public function publicArticles(string $locale, ?TranslationResolver $resolver = null): array
    {
        $resolver ??= app(TranslationResolver::class);

        $resolved = [];

        $relation = $this->articles();

        // Read the related articles (not the pivot) so the ambiguous `id` column
        // of the joined pivot table never overwrites the article's own id.
        $articles = Visibility::applyPublic($relation->getQuery())
            ->select($relation->getRelated()->getTable().'.*')
            ->get();

        foreach ($articles as $article) {
            if (! $article instanceof ArticleContract || ! $article instanceof TranslatableContract) {
                continue;
            }

            $item = $resolver->resolveArticle($article, $locale);

            if ($item instanceof ResolvedTranslation) {
                $resolved[] = $item;
            }
        }

        return $resolved;
    }

    /** @param Builder<static> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @param Builder<static> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function articleId(int|Model $article): int
    {
        return (int) ($article instanceof Model ? $article->getKey() : $article);
    }

    protected function nextArticleOrder(): int
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        return (int) DB::table($prefix.'collection_article')
            ->where('collection_id', $this->getKey())
            ->max('sort_order') + 1;
    }
}
