<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

interface CategoryContract
{
    public function parent(): BelongsTo;

    public function children(): HasMany;

    public function articles(): HasMany;
}
