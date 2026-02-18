<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

interface ArticleContract
{
    public function category(): BelongsTo;

    public function author(): MorphTo;

    public function versions(): HasMany;

    public function feedback(): HasMany;

    public function relatedArticles(): BelongsToMany;

    public function incrementViewCount(): void;
}
