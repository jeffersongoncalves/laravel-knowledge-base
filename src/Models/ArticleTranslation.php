<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use JeffersonGoncalves\KnowledgeBase\Enums\TranslationStatus;
use JeffersonGoncalves\KnowledgeBase\Models\Concerns\SyncsAuthoritativeTranslation;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleTranslationContract;
use JeffersonGoncalves\KnowledgeBase\Support\Locales;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

/**
 * @property int $id
 * @property int $article_id
 * @property string $locale
 * @property string $title
 * @property string $slug
 * @property string $content
 * @property string|null $excerpt
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property string|null $seo_keywords
 * @property TranslationStatus $status
 * @property Carbon|null $published_at
 * @property bool $is_outdated
 * @property Carbon|null $outdated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Article $article
 */
class ArticleTranslation extends Model implements ArticleTranslationContract
{
    use HasFactory;
    use SyncsAuthoritativeTranslation;

    protected $fillable = [
        'article_id',
        'locale',
        'title',
        'slug',
        'content',
        'excerpt',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'status',
        'published_at',
        'is_outdated',
        'outdated_at',
    ];

    protected $casts = [
        'status' => TranslationStatus::class,
        'published_at' => 'datetime',
        'is_outdated' => 'boolean',
        'outdated_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (config('knowledge-base.table_prefix') ?? '').'article_translations';
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::article(), 'article_id');
    }

    public function owner(): BelongsTo
    {
        return $this->article();
    }

    /** @param Builder<static> $query */
    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    /** @param Builder<static> $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', TranslationStatus::Published);
    }

    /** @param Builder<static> $query */
    public function scopeOutdated(Builder $query): Builder
    {
        return $query->where('is_outdated', true);
    }

    protected function ownerForeignKey(): string
    {
        return 'article_id';
    }

    /** @return list<string> */
    protected function mirroredAttributes(): array
    {
        return ['title', 'slug', 'content', 'excerpt', 'seo_title', 'seo_description', 'seo_keywords'];
    }

    protected function authoritativeLocaleValue(): string
    {
        // The authoritative version is always the configured default language
        // (see design D1); the article no longer chooses its own.
        return Locales::default();
    }
}
