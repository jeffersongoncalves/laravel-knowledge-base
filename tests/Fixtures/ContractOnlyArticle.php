<?php

namespace JeffersonGoncalves\KnowledgeBase\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use RuntimeException;

/**
 * A custom article model that implements ArticleContract directly, without
 * extending the package's Article. Declaring it fails if the contract gains
 * methods, which would break apps that do the same.
 */
class ContractOnlyArticle extends Model implements ArticleContract
{
    public function category(): BelongsTo
    {
        throw new RuntimeException('Not used.');
    }

    public function author(): MorphTo
    {
        throw new RuntimeException('Not used.');
    }

    public function versions(): HasMany
    {
        throw new RuntimeException('Not used.');
    }

    public function feedback(): HasMany
    {
        throw new RuntimeException('Not used.');
    }

    public function relatedArticles(): BelongsToMany
    {
        throw new RuntimeException('Not used.');
    }

    public function incrementViewCount(): void
    {
        //
    }
}
