<?php

use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleFeedback;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleRelation;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleVersion;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

beforeEach(function () {
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
