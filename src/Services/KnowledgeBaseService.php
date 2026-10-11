<?php

namespace JeffersonGoncalves\KnowledgeBase\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;
use JeffersonGoncalves\KnowledgeBase\Enums\TranslationStatus;
use JeffersonGoncalves\KnowledgeBase\Events\ArticleCreated;
use JeffersonGoncalves\KnowledgeBase\Events\ArticleFeedbackReceived;
use JeffersonGoncalves\KnowledgeBase\Events\ArticlePublished;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleFeedbackContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleTranslationContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\TranslatableArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\TranslatableContract;
use JeffersonGoncalves\KnowledgeBase\Support\Locales;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;
use JeffersonGoncalves\KnowledgeBase\Support\Visibility;

class KnowledgeBaseService
{
    public function createArticle(array $data, Model $author): Model
    {
        return DB::transaction(function () use ($data, $author) {
            $articleClass = ModelResolver::article();

            if (empty($data['slug'])) {
                $data['slug'] = $this->generateUniqueSlug($articleClass, (string) ($data['title'] ?? ''));
            }

            if (empty($data['visibility'])) {
                $data['visibility'] = config('knowledge-base.default_visibility', 'public');
            }

            /** @var Article $article */
            $article = new $articleClass;
            $article->fill($data);
            $article->author_type = $author->getMorphClass();
            $article->author_id = $author->getKey();

            if (empty($article->current_version)) {
                $article->current_version = 1;
            }

            $article->save();

            if (config('knowledge-base.versioning_enabled', true)) {
                $article->versions()->create([
                    'version_number' => 1,
                    'locale' => $this->versionLocale($article),
                    'title' => $article->title,
                    'content' => $article->content,
                    'excerpt' => $article->excerpt,
                    'editor_type' => $author->getMorphClass(),
                    'editor_id' => $author->getKey(),
                    'change_notes' => 'Initial version',
                    'created_at' => now(),
                ]);
            }

            $article = $article->fresh();

            event(new ArticleCreated($article));

            return $article;
        });
    }

    public function updateArticle(Model&ArticleContract $article, array $data, Model $editor, ?string $changeNotes = null, bool $flagOtherLocalesOutdated = false): Model
    {
        return DB::transaction(function () use ($article, $data, $editor, $changeNotes, $flagOtherLocalesOutdated) {
            /** @var Article $article */
            $article->fill($data);
            $article->save();

            if (config('knowledge-base.versioning_enabled', true)) {
                $newVersion = $article->current_version + 1;

                $article->versions()->create([
                    'version_number' => $newVersion,
                    'locale' => $this->versionLocale($article),
                    'title' => $article->title,
                    'content' => $article->content,
                    'excerpt' => $article->excerpt,
                    'editor_type' => $editor->getMorphClass(),
                    'editor_id' => $editor->getKey(),
                    'change_notes' => $changeNotes,
                    'created_at' => now(),
                ]);

                // current_version is intentionally not mass-assignable; set it directly.
                $article->current_version = $newVersion;
                $article->save();
            }

            if ($flagOtherLocalesOutdated) {
                $this->translatableArticle($article)->flagOtherTranslationsOutdated();
            }

            return $article->fresh();
        });
    }

    /**
     * Edit one language's version and record the history entry for that
     * language (see design D5 and D6). Editing clears its outdated flag.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateArticleTranslation(Model&ArticleContract $article, string $locale, array $data, Model $editor, ?string $changeNotes = null): Model
    {
        return DB::transaction(function () use ($article, $locale, $data, $editor, $changeNotes) {
            /** @var Article $article */
            $translation = $this->translatableArticle($article)->translation($locale);

            if (! $translation instanceof Model) {
                throw new InvalidArgumentException("The article has no version in [{$locale}].");
            }

            $translation->fill($data);
            $translation->save();

            if (config('knowledge-base.versioning_enabled', true)) {
                $newVersion = $article->current_version + 1;

                $article->versions()->create([
                    'version_number' => $newVersion,
                    'locale' => $locale,
                    'title' => $translation->getAttribute('title'),
                    'content' => $translation->getAttribute('content'),
                    'excerpt' => $translation->getAttribute('excerpt'),
                    'editor_type' => $editor->getMorphClass(),
                    'editor_id' => $editor->getKey(),
                    'change_notes' => $changeNotes,
                    'created_at' => now(),
                ]);

                // Refresh first: saving the translation may have mirrored new
                // values onto the main row, and saving a stale model would
                // overwrite them.
                $article->refresh();
                $article->current_version = $newVersion;
                $article->saveQuietly();
            }

            return $translation->fresh();
        });
    }

    /**
     * The article versions that are flagged outdated in a language (see design
     * D5).
     *
     * @return EloquentCollection<int, Model&ArticleTranslationContract>
     */
    public function outdatedTranslations(string $locale): EloquentCollection
    {
        $translationClass = ModelResolver::articleTranslation();

        return $translationClass::query()
            ->where('locale', $locale)
            ->where('is_outdated', true)
            ->get();
    }

    public function publishArticle(Model&ArticleContract $article): Model
    {
        $article->update([
            'status' => ArticleStatus::Published,
            'published_at' => now(),
        ]);

        event(new ArticlePublished($article));

        return $article->fresh();
    }

    public function archiveArticle(Model&ArticleContract $article): Model
    {
        $article->update([
            'status' => ArticleStatus::Archived,
        ]);

        return $article->fresh();
    }

    public function deleteArticle(Model&ArticleContract $article): bool
    {
        return $article->delete();
    }

    public function createCategory(array $data): Model
    {
        $categoryClass = ModelResolver::category();

        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug($categoryClass, (string) ($data['name'] ?? ''));
        }

        /** @var Model $category */
        $category = $categoryClass::create($data);

        return $category;
    }

    public function updateCategory(Model&CategoryContract $category, array $data): Model
    {
        $category->update($data);

        return $category->fresh();
    }

    public function deleteCategory(Model&CategoryContract $category): bool
    {
        return $category->delete();
    }

    public function addFeedback(Model&ArticleContract $article, bool $isHelpful, ?Model $user = null, ?string $comment = null, ?string $ipAddress = null): Model
    {
        if (! config('knowledge-base.feedback_enabled', true)) {
            throw new \RuntimeException('Feedback is disabled.');
        }

        /** @var Model&ArticleFeedbackContract $feedback */
        $feedback = $article->feedback()->create([
            'user_type' => $user?->getMorphClass(),
            'user_id' => $user?->getKey(),
            'is_helpful' => $isHelpful,
            'comment' => $comment,
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);

        if ($isHelpful) {
            $article->increment('helpful_count');
        } else {
            $article->increment('not_helpful_count');
        }

        event(new ArticleFeedbackReceived($article, $feedback));

        return $feedback;
    }

    /**
     * Search published articles.
     *
     * By default only publicly visible articles are returned (see
     * Article::publiclyVisible()), so internal articles and articles in
     * internal, inactive or deleted categories are excluded. Staff content is
     * strictly opt-in: `include_internal` returns every published article, and
     * `visibility: internal` returns only the staff-only ones.
     *
     * @param  array<string, mixed>  $options
     * @return Collection<int, Article>
     */
    public function search(string $query, array $options = []): Collection
    {
        if (! empty($options['locale'])) {
            return $this->searchLocalized($query, (string) $options['locale'], $options);
        }

        $articleClass = ModelResolver::article();

        /** @var Builder<Article> $builder */
        $builder = $articleClass::query();

        $this->applySearchConstraint($builder, $query);
        $this->applySearchFilters($builder, $options);

        $limit = $options['limit'] ?? config('knowledge-base.search_results_limit', 20);

        return $builder->orderByDesc('view_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Search one language (see design D4).
     *
     * Matches readable versions in the requested language, plus — under the
     * fallback setting — the authoritative version of articles that have no
     * readable version in that language. Visibility rules apply unchanged and
     * every result reports the language that was served.
     *
     * @param  array<string, mixed>  $options
     * @return Collection<int, Article>
     */
    protected function searchLocalized(string $query, string $locale, array $options): Collection
    {
        $articleClass = ModelResolver::article();
        $translationClass = ModelResolver::articleTranslation();

        $filters = function (Builder $builder) use ($options): void {
            $this->applySearchFilters($builder, $options);
        };

        /** @var array<int, string> $served article id => served locale */
        $served = [];

        $requested = $translationClass::query()
            ->where('locale', $locale)
            ->where('status', TranslationStatus::Published)
            ->whereHas('article', $filters);

        $this->applyTranslationSearch($requested, $query, $locale);

        foreach ($requested->get() as $translation) {
            $served[(int) $translation->getAttribute('article_id')] = $locale;
        }

        if (Locales::missingTranslation() === Locales::MISSING_FALLBACK) {
            // The authoritative version is always the configured default
            // language (see design D1), so the fallback is the default-language
            // version of articles with no readable version in the requested one.
            $fallback = $translationClass::query()
                ->where('locale', Locales::default())
                ->where('status', TranslationStatus::Published)
                ->whereNotIn('article_id', array_keys($served))
                ->whereHas('article', function (Builder $builder) use ($filters, $locale): void {
                    $filters($builder);

                    $builder->whereDoesntHave('translations', function (Builder $translations) use ($locale): void {
                        $translations->where('locale', $locale)
                            ->where('status', TranslationStatus::Published);
                    });
                });

            $this->applyTranslationSearch($fallback, $query, Locales::default());

            foreach ($fallback->get() as $translation) {
                $served[(int) $translation->getAttribute('article_id')] = (string) $translation->getAttribute('locale');
            }
        }

        if ($served === []) {
            /** @var Collection<int, Article> $empty */
            $empty = new EloquentCollection;

            return $empty;
        }

        $limit = $options['limit'] ?? config('knowledge-base.search_results_limit', 20);

        /** @var EloquentCollection<int, Article> $articles */
        $articles = $articleClass::query()
            ->whereIn('id', array_keys($served))
            ->orderByDesc('view_count')
            ->limit($limit)
            ->get();

        // Attach the served language version to each result (one query), so
        // callers can show the title and slug in that language.
        $servedTranslations = $translationClass::query()
            ->whereIn('article_id', $articles->modelKeys())
            ->get()
            ->filter(fn (Model $translation): bool => $translation->getAttribute('locale') === ($served[(int) $translation->getAttribute('article_id')] ?? null))
            ->keyBy('article_id');

        foreach ($articles as $article) {
            $article->served_locale = $served[(int) $article->getKey()];
            $article->served_translation = $servedTranslations->get($article->getKey());
        }

        return $articles;
    }

    /**
     * The language a history entry is recorded in: the authoritative language
     * for translatable articles, the default language otherwise (custom models
     * that implement ArticleContract without the localization contract).
     */
    protected function versionLocale(Model $article): string
    {
        return $article instanceof TranslatableContract
            ? $article->authoritativeLocale()
            : Locales::default();
    }

    protected function translatableArticle(Model $article): Model&TranslatableArticleContract
    {
        if (! $article instanceof TranslatableArticleContract) {
            throw new InvalidArgumentException('The configured article model does not implement '.TranslatableArticleContract::class.'.');
        }

        return $article;
    }

    /**
     * Apply the category and visibility filters shared by the plain and the
     * localized search.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     * @param  array<string, mixed>  $options
     */
    protected function applySearchFilters(Builder $builder, array $options): void
    {
        if (isset($options['category_id'])) {
            $builder->where('category_id', $options['category_id']);
        }

        if (isset($options['visibility'])) {
            $visibility = $options['visibility'] instanceof ArticleVisibility
                ? $options['visibility']
                : ArticleVisibility::from($options['visibility']);

            $visibility === ArticleVisibility::Public
                ? Visibility::applyPublic($builder)
                : Visibility::applyStaffOnly($builder);
        } elseif ($options['include_internal'] ?? false) {
            $builder->where('status', ArticleStatus::Published);
        } else {
            Visibility::applyPublic($builder);
        }
    }

    /**
     * Apply the textual search constraint to the query.
     *
     * Uses a native full-text search when the underlying driver supports it
     * (and the configured search engine is the database), otherwise falls back
     * to a wildcard LIKE search with the user input escaped so that `%` and `_`
     * are treated literally instead of as wildcards.
     *
     * @param  Builder<Article>  $builder
     */
    protected function applySearchConstraint(Builder $builder, string $query): void
    {
        $driver = $builder->getModel()->getConnection()->getDriverName();
        $engine = config('knowledge-base.search_engine', 'database');

        if ($engine === 'database' && in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $builder->whereFullText(['title', 'content'], $query);

            return;
        }

        $escaped = addcslashes($query, '%_\\');

        $builder->where(function (Builder $q) use ($escaped) {
            $q->whereRaw('title LIKE ? ESCAPE ?', ["%{$escaped}%", '\\'])
                ->orWhereRaw('content LIKE ? ESCAPE ?', ["%{$escaped}%", '\\']);
        });
    }

    /**
     * The translated title/content twin of applySearchConstraint(): native
     * full-text where supported (with the locale's text-search configuration on
     * PostgreSQL, see design D4), LIKE elsewhere.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     */
    protected function applyTranslationSearch(Builder $builder, string $query, string $locale): void
    {
        $driver = $builder->getModel()->getConnection()->getDriverName();
        $engine = config('knowledge-base.search_engine', 'database');

        if ($engine === 'database' && in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $options = $driver === 'pgsql' ? ['language' => Locales::searchLanguage($locale)] : [];

            $builder->whereFullText(['title', 'content'], $query, $options);

            return;
        }

        $escaped = addcslashes($query, '%_\\');

        $builder->where(function (Builder $q) use ($escaped) {
            $q->whereRaw('title LIKE ? ESCAPE ?', ["%{$escaped}%", '\\'])
                ->orWhereRaw('content LIKE ? ESCAPE ?', ["%{$escaped}%", '\\']);
        });
    }

    /**
     * Generate a slug from the given value that is unique for the model,
     * appending an incrementing suffix on collision (soft-deleted rows
     * included, since the underlying unique constraint covers them).
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function generateUniqueSlug(string $modelClass, string $value): string
    {
        $base = Str::slug($value);

        if ($base === '') {
            $base = (string) Str::uuid();
        }

        $slug = $base;
        $counter = 1;

        while ($this->slugExists($modelClass, $slug)) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function slugExists(string $modelClass, string $slug): bool
    {
        $query = $modelClass::query()->where('slug', $slug);

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            // @phpstan-ignore method.notFound (withTrashed() is provided by the SoftDeletes trait)
            $query->withTrashed();
        }

        return $query->exists();
    }
}
