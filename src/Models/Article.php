<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

/**
 * @property int $id
 * @property string $uuid
 * @property int $category_id
 * @property string $title
 * @property string $slug
 * @property string $content
 * @property string|null $excerpt
 * @property string $author_type
 * @property int $author_id
 * @property ArticleStatus $status
 * @property ArticleVisibility $visibility
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property string|null $seo_keywords
 * @property int $view_count
 * @property int $helpful_count
 * @property int $not_helpful_count
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property int $current_version
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Category $category
 * @property-read \Illuminate\Database\Eloquent\Model $author
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ArticleVersion> $versions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ArticleFeedback> $feedback
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Article> $relatedArticles
 */
class Article extends Model implements ArticleContract
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'category_id',
        'title',
        'slug',
        'content',
        'excerpt',
        'author_type',
        'author_id',
        'status',
        'visibility',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'view_count',
        'helpful_count',
        'not_helpful_count',
        'published_at',
        'current_version',
        'metadata',
    ];

    protected $casts = [
        'status' => ArticleStatus::class,
        'visibility' => ArticleVisibility::class,
        'published_at' => 'datetime',
        'metadata' => 'array',
        'view_count' => 'integer',
        'helpful_count' => 'integer',
        'not_helpful_count' => 'integer',
        'current_version' => 'integer',
    ];

    public function getTable(): string
    {
        return (config('knowledge-base.table_prefix') ?? '').'articles';
    }

    protected static function booted(): void
    {
        static::creating(function (Article $article) {
            if (empty($article->uuid)) {
                $article->uuid = (string) Str::uuid();
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::category(), 'category_id');
    }

    public function author(): MorphTo
    {
        return $this->morphTo('author');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ModelResolver::articleVersion(), 'article_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(ModelResolver::articleFeedback(), 'article_id');
    }

    public function relatedArticles(): BelongsToMany
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        return $this->belongsToMany(
            ModelResolver::article(),
            $prefix.'article_relations',
            'article_id',
            'related_article_id'
        )->withPivot('sort_order')->withTimestamps();
    }

    /** @param Builder<static> $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ArticleStatus::Published);
    }

    /** @param Builder<static> $query */
    public function scopeByVisibility(Builder $query, ArticleVisibility $visibility): Builder
    {
        return $query->where('visibility', $visibility);
    }

    /** @param Builder<static> $query */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', ArticleStatus::Draft);
    }

    /** @param Builder<static> $query */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', ArticleStatus::Archived);
    }

    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
