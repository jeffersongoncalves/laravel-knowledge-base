<?php

use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleFeedback;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleRelation;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleVersion;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\CustomArticle;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    ModelResolver::flushCache();
});

afterEach(function () {
    config(['knowledge-base.models.article' => Article::class]);
    ModelResolver::flushCache();
});

it('resolves article model', function () {
    expect(ModelResolver::article())->toBe(Article::class);
});

it('resolves category model', function () {
    expect(ModelResolver::category())->toBe(Category::class);
});

it('resolves article version model', function () {
    expect(ModelResolver::articleVersion())->toBe(ArticleVersion::class);
});

it('resolves article feedback model', function () {
    expect(ModelResolver::articleFeedback())->toBe(ArticleFeedback::class);
});

it('resolves article relation model', function () {
    expect(ModelResolver::articleRelation())->toBe(ArticleRelation::class);
});

it('caches resolved models', function () {
    $first = ModelResolver::article();
    $second = ModelResolver::article();

    expect($first)->toBe($second);
});

it('throws exception for invalid model class', function () {
    config(['knowledge-base.models.article' => 'NonExistent\\Model']);
    ModelResolver::flushCache();

    ModelResolver::article();
})->throws(InvalidArgumentException::class);

it('throws exception for model not implementing contract', function () {
    config(['knowledge-base.models.article' => Category::class]);
    ModelResolver::flushCache();

    ModelResolver::article();
})->throws(InvalidArgumentException::class);

it('flushes cache correctly', function () {
    ModelResolver::article();
    ModelResolver::flushCache();

    config(['knowledge-base.models.article' => 'NonExistent\\Model']);

    ModelResolver::article();
})->throws(InvalidArgumentException::class);

it('resolves an overridden article model from config', function () {
    config(['knowledge-base.models.article' => CustomArticle::class]);
    ModelResolver::flushCache();

    expect(ModelResolver::article())->toBe(CustomArticle::class);
});

it('uses the overridden model when creating articles', function () {
    config(['knowledge-base.models.article' => CustomArticle::class]);
    ModelResolver::flushCache();

    User::createTable();
    $category = Category::factory()->create();
    $author = User::create([
        'name' => 'John',
        'email' => 'john@example.com',
    ]);

    $service = app(KnowledgeBaseService::class);

    $article = $service->createArticle([
        'category_id' => $category->id,
        'title' => 'Overridden Model Article',
        'content' => 'Content.',
    ], $author);

    expect($article)->toBeInstanceOf(CustomArticle::class);
});
