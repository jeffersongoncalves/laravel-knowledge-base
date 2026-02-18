<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

interface ArticleVersionContract
{
    /** @return BelongsTo<\Illuminate\Database\Eloquent\Model&ArticleContract, $this> */
    public function article(): BelongsTo;

    public function editor(): MorphTo;
}
