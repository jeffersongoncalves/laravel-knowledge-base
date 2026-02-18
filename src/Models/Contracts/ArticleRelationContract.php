<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

interface ArticleRelationContract
{
    /** @return BelongsTo<\Illuminate\Database\Eloquent\Model&ArticleContract, $this> */
    public function article(): BelongsTo;

    /** @return BelongsTo<\Illuminate\Database\Eloquent\Model&ArticleContract, $this> */
    public function relatedArticle(): BelongsTo;
}
