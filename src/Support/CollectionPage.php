<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A public collection page (see design D3): the collection resolved for a
 * requested language and its publicly visible articles, in order.
 */
final class CollectionPage
{
    /**
     * @param  list<ResolvedTranslation>  $articles
     */
    public function __construct(
        public Model $collection,
        public Model $translation,
        public string $locale,
        public string $slug,
        public bool $isFallback,
        public ?string $redirectSlug,
        public array $articles,
    ) {}
}
