<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleRelationContract;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

/**
 * @property int $id
 * @property int $article_id
 * @property int $related_article_id
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Model&\JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract $article
 * @property-read \Illuminate\Database\Eloquent\Model&\JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract $relatedArticle
 */
class ArticleRelation extends Pivot implements ArticleRelationContract
{
    public $incrementing = true;

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function getTable(): string
    {
        return (config('knowledge-base.table_prefix') ?? '').'article_relations';
    }

    /** @return BelongsTo<\Illuminate\Database\Eloquent\Model&\JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::article(), 'article_id');
    }

    /** @return BelongsTo<\Illuminate\Database\Eloquent\Model&\JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract, $this> */
    public function relatedArticle(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::article(), 'related_article_id');
    }
}
