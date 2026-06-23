<?php

namespace JeffersonGoncalves\KnowledgeBase\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;
use JeffersonGoncalves\KnowledgeBase\Events\ArticleCreated;
use JeffersonGoncalves\KnowledgeBase\Events\ArticleFeedbackReceived;
use JeffersonGoncalves\KnowledgeBase\Events\ArticlePublished;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleFeedbackContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryContract;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

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

    public function updateArticle(Model&ArticleContract $article, array $data, Model $editor, ?string $changeNotes = null): Model
    {
        return DB::transaction(function () use ($article, $data, $editor, $changeNotes) {
            /** @var Article $article */
            $article->fill($data);
            $article->save();

            if (config('knowledge-base.versioning_enabled', true)) {
                $newVersion = $article->current_version + 1;

                $article->versions()->create([
                    'version_number' => $newVersion,
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

            return $article->fresh();
        });
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
     * Internal articles are excluded by default. Including them is strictly
     * opt-in, either by passing an explicit `visibility` option or by setting
     * `include_internal` to true.
     *
     * @param  array<string, mixed>  $options
     * @return Collection<int, Article>
     */
    public function search(string $query, array $options = []): Collection
    {
        $articleClass = ModelResolver::article();

        /** @var Builder<Article> $builder */
        $builder = $articleClass::query()
            ->where('status', ArticleStatus::Published);

        $this->applySearchConstraint($builder, $query);

        if (isset($options['category_id'])) {
            $builder->where('category_id', $options['category_id']);
        }

        if (isset($options['visibility'])) {
            $visibility = $options['visibility'] instanceof ArticleVisibility
                ? $options['visibility']
                : ArticleVisibility::from($options['visibility']);

            $builder->where('visibility', $visibility);
        } elseif (! ($options['include_internal'] ?? false)) {
            $builder->where('visibility', '!=', ArticleVisibility::Internal);
        }

        $limit = $options['limit'] ?? config('knowledge-base.search_results_limit', 20);

        return $builder->orderByDesc('view_count')
            ->limit($limit)
            ->get();
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
