<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

interface CategoryContract
{
    public function parent(): BelongsTo;

    public function children(): HasMany;

    public function articles(): HasMany;

    public function translations(): HasMany;

    public function translation(string $locale): ?Model;

    public function authoritativeTranslation(): ?Model;

    /** @return list<string> */
    public function translationAttributes(): array;
}
