<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleVersionContract;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

/**
 * @property int $id
 * @property int $article_id
 * @property int $version_number
 * @property string $title
 * @property string $content
 * @property string|null $excerpt
 * @property string $editor_type
 * @property int $editor_id
 * @property string|null $change_notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \Illuminate\Database\Eloquent\Model&\JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract $article
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent $editor
 */
class ArticleVersion extends Model implements ArticleVersionContract
{
    public $timestamps = false;

    protected $fillable = [
        'article_id',
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

    /** @return BelongsTo<\Illuminate\Database\Eloquent\Model&\JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::article(), 'article_id');
    }

    public function editor(): MorphTo
    {
        return $this->morphTo('editor');
    }
}
