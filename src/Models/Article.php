<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;
use JeffersonGoncalves\KnowledgeBase\Enums\TranslationStatus;
use JeffersonGoncalves\KnowledgeBase\Models\Concerns\HasTranslations;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\TranslatableArticleContract;
use JeffersonGoncalves\KnowledgeBase\Support\Locales;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;
use JeffersonGoncalves\KnowledgeBase\Support\Visibility;

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
 * @property Carbon|null $published_at
 * @property int $current_version
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Category $category
 * @property-read Model $author
 * @property-read Collection<int, ArticleVersion> $versions
 * @property-read Collection<int, ArticleTranslation> $translations
 * @property-read Collection<int, ArticleFeedback> $feedback
 * @property-read Collection<int, Article> $relatedArticles
 */
class Article extends Model implements ArticleContract, TranslatableArticleContract
{
    use HasFactory;
    use HasTranslations;
    use SoftDeletes;

    /**
     * Mass-assignable attributes.
     *
     * Sensitive columns (uuid, view_count, helpful_count, not_helpful_count and
     * current_version) are intentionally excluded. They must only ever be set by
     * internal package logic (UUID generation, counter increments and version
     * bumps), never through user-supplied input.
     */
    protected $fillable = [
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
        'published_at',
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

    /**
     * The language a localized search actually served for this article. Set on
     * results only; never persisted and not part of an article row.
     */
    public ?string $served_locale = null;

    /**
     * The language version a localized search actually served for this
     * article (title, slug, excerpt and content in that language). Set on
     * results only; never persisted. The article's own columns keep the
     * authoritative version, so saving a search result never changes them.
     */
    public ?Model $served_translation = null;

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

    /**
     * Mark every version other than the authoritative one as outdated
     * (see design D5).
     */
    public function flagOtherTranslationsOutdated(): int
    {
        return $this->translations()
            ->where('locale', '!=', $this->authoritativeLocale())
            ->update([
                'is_outdated' => true,
                'outdated_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Articles always have a version in the configured default language; it is
     * the authoritative one and cannot be chosen per article (see design D1 and
     * the "Default language is authoritative" requirement).
     */
    public function authoritativeLocale(): string
    {
        return Locales::default();
    }

    /** @return class-string<Model> */
    protected function translationModel(): string
    {
        return ModelResolver::articleTranslation();
    }

    protected function translationForeignKey(): string
    {
        return 'article_id';
    }

    /** @return list<string> */
    protected function mirroredAttributes(): array
    {
        return ['title', 'slug', 'content', 'excerpt', 'seo_title', 'seo_description', 'seo_keywords'];
    }

    protected function authoritativeStatusDiffers(Model $translation): bool
    {
        return $translation->getAttribute('status') !== $this->authoritativeStatus();
    }

    protected function applyAuthoritativeStatus(Model $translation): void
    {
        $translation->setAttribute('status', $this->authoritativeStatus());
    }

    protected function authoritativeStatus(): TranslationStatus
    {
        return $this->status === ArticleStatus::Published
            ? TranslationStatus::Published
            : TranslationStatus::Draft;
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

    /**
     * Published articles, whatever their visibility or category.
     *
     * Not safe for customer-facing pages: it includes internal articles and
     * articles in internal, inactive or deleted categories. Use
     * publiclyVisible() for anything a customer can see.
     *
     * @param  Builder<static>  $query
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ArticleStatus::Published);
    }

    /**
     * Articles that may be shown to customers: published, public, and in a
     * category that, along with every ancestor, is active, public and not
     * deleted.
     *
     * @param  Builder<static>  $query
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return Visibility::applyPublic($query);
    }

    /**
     * Published articles that are not publicly visible: internal articles,
     * and public articles in an internal, inactive or deleted category.
     *
     * @param  Builder<static>  $query
     */
    public function scopeStaffOnly(Builder $query): Builder
    {
        return Visibility::applyStaffOnly($query);
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
        if (! config('knowledge-base.track_views', true)) {
            return;
        }

        $this->increment('view_count');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
