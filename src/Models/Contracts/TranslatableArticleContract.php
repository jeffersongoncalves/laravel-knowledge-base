<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

interface TranslatableArticleContract extends TranslatableContract
{
    public function flagOtherTranslationsOutdated(): int;
}
