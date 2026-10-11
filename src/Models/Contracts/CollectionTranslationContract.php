<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

interface CollectionTranslationContract
{
    public function collection(): BelongsTo;
}
