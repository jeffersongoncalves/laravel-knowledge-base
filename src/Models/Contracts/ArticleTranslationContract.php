<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

interface ArticleTranslationContract
{
    public function article(): BelongsTo;

    public function isOutdated(): bool;

    public function markUpToDate(): static;
}
