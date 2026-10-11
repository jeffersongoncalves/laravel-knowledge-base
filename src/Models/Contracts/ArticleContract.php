<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

interface ArticleContract
{
    public function category(): BelongsTo;

    public function author(): MorphTo;

    public function versions(): HasMany;

    public function translations(): HasMany;

    public function translation(string $locale): ?Model;

    public function authoritativeTranslation(): ?Model;

    /** @return list<string> */
    public function translationAttributes(): array;

    public function feedback(): HasMany;

    public function relatedArticles(): BelongsToMany;

    public function changeSourceLocale(string $locale): static;

    public function flagOtherTranslationsOutdated(): int;

    public function incrementViewCount(): void;
}
