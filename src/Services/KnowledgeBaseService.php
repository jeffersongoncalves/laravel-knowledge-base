<?php

namespace JeffersonGoncalves\KnowledgeBase\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Events\ArticleCreated;
use JeffersonGoncalves\KnowledgeBase\Events\ArticleFeedbackReceived;
use JeffersonGoncalves\KnowledgeBase\Events\ArticlePublished;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleFeedbackContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryContract;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

class KnowledgeBaseService
{
    /** @return Model&ArticleContract */
    public function createArticle(array $data, Model $author): Model
    {
        return DB::transaction(function () use ($data, $author) {
            $articleClass = ModelResolver::article();

            /** @var Model&ArticleContract $article */
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

    /** @return Model&ArticleContract */
    public function updateArticle(Model&ArticleContract $article, array $data, Model $editor, ?string $changeNotes = null): Model
    {
        return DB::transaction(function () use ($article, $data, $editor, $changeNotes) {
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

                $article->update(['current_version' => $newVersion]);
            }

            return $article->fresh();
        });
    }

    /** @return Model&ArticleContract */
    public function publishArticle(Model&ArticleContract $article): Model
    {
        $article->update([
            'status' => ArticleStatus::Published,
            'published_at' => now(),
        ]);

        event(new ArticlePublished($article));

        return $article->fresh();
    }

    /** @return Model&ArticleContract */
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

    /** @return Model&CategoryContract */
    public function createCategory(array $data): Model
    {
        if (! isset($data['slug']) && isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $categoryClass = ModelResolver::category();

        return $categoryClass::create($data);
    }

    /** @return Model&CategoryContract */
    public function updateCategory(Model&CategoryContract $category, array $data): Model
    {
        $category->update($data);

        return $category->fresh();
    }

    public function deleteCategory(Model&CategoryContract $category): bool
    {
        return $category->delete();
    }

    /** @return Model&ArticleFeedbackContract */
    public function addFeedback(Model&ArticleContract $article, bool $isHelpful, ?Model $user = null, ?string $comment = null, ?string $ipAddress = null): Model
    {
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

    public function search(string $query, array $options = []): Collection
    {
        $articleClass = ModelResolver::article();

        $builder = $articleClass::query()
            ->published()
            ->where(function ($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                    ->orWhere('content', 'LIKE', "%{$query}%");
            });

        if (isset($options['category_id'])) {
            $builder->where('category_id', $options['category_id']);
        }

        if (isset($options['visibility'])) {
            $builder->where('visibility', $options['visibility']);
        }

        $limit = $options['limit'] ?? config('knowledge-base.search_results_limit', 20);

        return $builder->orderByDesc('view_count')
            ->limit($limit)
            ->get();
    }
}
