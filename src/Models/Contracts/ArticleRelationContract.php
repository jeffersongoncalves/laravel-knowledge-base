<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

interface ArticleRelationContract
{
    public function article(): BelongsTo;

    public function relatedArticle(): BelongsTo;
}
