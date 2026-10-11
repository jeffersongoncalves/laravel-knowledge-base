<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * One node of the public category tree (see design D3): a category resolved for
 * a requested language, with its number of publicly visible articles and its
 * publicly visible children.
 */
final class CategoryNode
{
    /**
     * @param  list<CategoryNode>  $children
     */
    public function __construct(
        public Model $category,
        public Model $translation,
        public string $locale,
        public string $slug,
        public string $name,
        public int $articlesCount,
        public array $children = [],
    ) {}
}
