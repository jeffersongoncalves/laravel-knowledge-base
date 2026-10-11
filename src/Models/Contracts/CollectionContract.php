<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use JeffersonGoncalves\KnowledgeBase\Support\ResolvedTranslation;
use JeffersonGoncalves\KnowledgeBase\Support\TranslationResolver;

interface CollectionContract
{
    /**
     * The articles in the collection, in their chosen order.
     */
    public function articles(): BelongsToMany;

    /**
     * Add an article at the end (or at the given position). Adding an article
     * that is already in the collection is rejected.
     */
    public function addArticle(int|Model $article, ?int $sortOrder = null): void;

    /**
     * Remove an article from the collection. The article itself is untouched.
     */
    public function removeArticle(int|Model $article): void;

    /**
     * Replace the order of the given articles with their sequence in the given
     * list (see the "Keeping the chosen order" requirement).
     *
     * @param  array<int, int|Model>  $articles
     */
    public function reorderArticles(array $articles): void;

    /**
     * The ids of the articles in the collection, in order.
     *
     * @return list<int>
     */
    public function articleIds(): array;

    /**
     * The public view of the collection (see design D2): its publicly visible
     * articles, in order, each resolved for the requested language.
     *
     * @return list<ResolvedTranslation>
     */
    public function publicArticles(string $locale, ?TranslationResolver $resolver = null): array;
}
