<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A public category page (see design D3): the category resolved for a requested
 * language, its publicly visible child categories and its publicly visible
 * articles, each resolved for that language.
 */
final class CategoryPage
{
    /**
     * @param  list<CategoryNode>  $children
     * @param  list<ResolvedTranslation>  $articles
     */
    public function __construct(
        public Model $category,
        public Model $translation,
        public string $locale,
        public string $slug,
        public string $name,
        public ?string $redirectSlug,
        public array $children,
        public array $articles,
    ) {}
}
