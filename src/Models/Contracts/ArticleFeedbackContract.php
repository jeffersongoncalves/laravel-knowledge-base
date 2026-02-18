<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

interface ArticleFeedbackContract
{
    public function article(): BelongsTo;

    public function user(): MorphTo;
}
