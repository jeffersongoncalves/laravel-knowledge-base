<?php

use Illuminate\Database\QueryException;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleRelation;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    User::createTable();
    $this->category = Category::factory()->create();
    $this->user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $this->makeArticle = fn () => Article::factory()->create([
        'category_id' => $this->category->id,
        'author_type' => $this->user->getMorphClass(),
        'author_id' => $this->user->id,
    ]);
});

it('attaches related articles via the pivot', function () {
    $article = ($this->makeArticle)();
    $related = ($this->makeArticle)();

    $article->relatedArticles()->attach($related->id, ['sort_order' => 3]);

    $loaded = $article->fresh()->relatedArticles;

    expect($loaded)->toHaveCount(1);
    expect($loaded->first()->id)->toBe($related->id);
    expect($loaded->first()->pivot->sort_order)->toBe(3);
});

it('uses the article_relations table with prefix', function () {
    $relation = new ArticleRelation;

    expect($relation->getTable())->toBe('kb_article_relations');
});

it('exposes article and related article relations on the pivot', function () {
    $article = ($this->makeArticle)();
    $related = ($this->makeArticle)();

    $article->relatedArticles()->attach($related->id, ['sort_order' => 1]);

    $relation = ArticleRelation::query()->first();

    expect($relation->article->id)->toBe($article->id);
    expect($relation->relatedArticle->id)->toBe($related->id);
});

it('enforces uniqueness of an article relation pair', function () {
    $article = ($this->makeArticle)();
    $related = ($this->makeArticle)();

    $article->relatedArticles()->attach($related->id);

    expect(fn () => ArticleRelation::create([
        'article_id' => $article->id,
        'related_article_id' => $related->id,
    ]))->toThrow(QueryException::class);
});
