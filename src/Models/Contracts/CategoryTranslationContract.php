<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

interface CategoryTranslationContract
{
    public function category(): BelongsTo;
}
