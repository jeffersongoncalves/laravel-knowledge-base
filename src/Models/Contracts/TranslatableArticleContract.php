<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

interface TranslatableArticleContract extends TranslatableContract
{
    public function changeSourceLocale(string $locale): static;

    public function flagOtherTranslationsOutdated(): int;
}
