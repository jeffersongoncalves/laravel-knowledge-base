<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

interface CategoryContract
{
    /** @return BelongsTo<\Illuminate\Database\Eloquent\Model&CategoryContract, $this> */
    public function parent(): BelongsTo;

    /** @return HasMany<\Illuminate\Database\Eloquent\Model&CategoryContract, $this> */
    public function children(): HasMany;

    /** @return HasMany<\Illuminate\Database\Eloquent\Model&ArticleContract, $this> */
    public function articles(): HasMany;
}
