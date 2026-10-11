<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * What a reader gets for one article or category: the version that was served
 * and the language it is in (see design D3). The slug is the served version's
 * slug, so a page can redirect when the requested language was not available.
 */
final readonly class ResolvedTranslation
{
    public function __construct(
        public Model $model,
        public Model $translation,
        public string $locale,
        public string $slug,
    ) {}
}
