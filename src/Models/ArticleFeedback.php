<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleFeedbackContract;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

/**
 * @property int $id
 * @property int $article_id
 * @property string|null $user_type
 * @property int|null $user_id
 * @property bool $is_helpful
 * @property string|null $comment
 * @property string|null $ip_address
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \Illuminate\Database\Eloquent\Model&\JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract $article
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent|null $user
 */
class ArticleFeedback extends Model implements ArticleFeedbackContract
{
    public $timestamps = false;

    protected $fillable = [
        'article_id',
        'user_type',
        'user_id',
        'is_helpful',
        'comment',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'is_helpful' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (config('knowledge-base.table_prefix') ?? '').'article_feedback';
    }

    /** @return BelongsTo<\Illuminate\Database\Eloquent\Model&\JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::article(), 'article_id');
    }

    public function user(): MorphTo
    {
        return $this->morphTo('user');
    }
}
