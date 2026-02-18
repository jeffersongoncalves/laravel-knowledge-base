<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

interface ArticleContract
{
    /** @return BelongsTo<\Illuminate\Database\Eloquent\Model&CategoryContract, $this> */
    public function category(): BelongsTo;

    public function author(): MorphTo;

    /** @return HasMany<\Illuminate\Database\Eloquent\Model&ArticleVersionContract, $this> */
    public function versions(): HasMany;

    /** @return HasMany<\Illuminate\Database\Eloquent\Model&ArticleFeedbackContract, $this> */
    public function feedback(): HasMany;

    /** @return BelongsToMany<\Illuminate\Database\Eloquent\Model&ArticleContract, $this> */
    public function relatedArticles(): BelongsToMany;

    public function incrementViewCount(): void;
}
