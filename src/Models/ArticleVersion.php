<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleVersionContract;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

/**
 * @property int $id
 * @property int $article_id
 * @property string|null $locale
 * @property int $version_number
 * @property string $title
 * @property string $content
 * @property string|null $excerpt
 * @property string $editor_type
 * @property int $editor_id
 * @property string|null $change_notes
 * @property Carbon $created_at
 * @property-read Article $article
 * @property-read Model $editor
 */
class ArticleVersion extends Model implements ArticleVersionContract
{
    public $timestamps = false;

    protected $fillable = [
        'article_id',
        'locale',
        'version_number',
        'title',
        'content',
        'excerpt',
        'editor_type',
        'editor_id',
        'change_notes',
        'created_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (config('knowledge-base.table_prefix') ?? '').'article_versions';
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::article(), 'article_id');
    }

    public function editor(): MorphTo
    {
        return $this->morphTo('editor');
    }
}
