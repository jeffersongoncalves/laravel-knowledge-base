<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A main row (article or category) with a version per language.
 *
 * Kept separate from ArticleContract and CategoryContract so that adding
 * localization does not break custom models implementing those contracts
 * directly. The package's own models implement both.
 */
interface TranslatableContract
{
    public function translations(): HasMany;

    public function translation(string $locale): ?Model;

    public function authoritativeTranslation(): ?Model;

    /**
     * The language of the version this row mirrors.
     */
    public function authoritativeLocale(): string;

    /**
     * The main-row attributes that mirror the authoritative version.
     *
     * @return list<string>
     */
    public function translationAttributes(): array;
}
