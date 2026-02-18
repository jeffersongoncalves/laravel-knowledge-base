<?php

use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    User::createTable();
});

it('can create an article', function () {
    $category = Category::factory()->create();
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $article = Article::factory()->create([
        'category_id' => $category->id,
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
        'title' => 'How to get started',
    ]);

    expect($article)
        ->title->toBe('How to get started')
        ->status->toBe(ArticleStatus::Draft)
        ->visibility->toBe(ArticleVisibility::Public);
});

it('generates uuid on creation', function () {
    $category = Category::factory()->create();
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $article = Article::factory()->create([
        'category_id' => $category->id,
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
    ]);

    expect($article->uuid)->not->toBeNull()->toBeString();
});

it('uses table prefix from config', function () {
    $article = new Article;

    expect($article->getTable())->toBe('kb_articles');
});

it('belongs to a category', function () {
    $category = Category::factory()->create();
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $article = Article::factory()->create([
        'category_id' => $category->id,
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
    ]);

    expect($article->category->id)->toBe($category->id);
});

it('has morph to author', function () {
    $category = Category::factory()->create();
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $article = Article::factory()->create([
        'category_id' => $category->id,
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
    ]);

    expect($article->author->id)->toBe($user->id);
});

it('scopes published articles', function () {
    $category = Category::factory()->create();
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    Article::factory()->create([
        'category_id' => $category->id,
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
        'status' => ArticleStatus::Draft,
    ]);

    Article::factory()->create([
        'category_id' => $category->id,
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
        'status' => ArticleStatus::Published,
        'published_at' => now(),
    ]);

    expect(Article::published()->count())->toBe(1);
});

it('scopes by visibility', function () {
    $category = Category::factory()->create();
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    Article::factory()->create([
        'category_id' => $category->id,
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
        'visibility' => ArticleVisibility::Public,
    ]);

    Article::factory()->create([
        'category_id' => $category->id,
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
        'visibility' => ArticleVisibility::Internal,
    ]);

    expect(Article::byVisibility(ArticleVisibility::Internal)->count())->toBe(1);
});

it('increments view count', function () {
    $category = Category::factory()->create();
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $article = Article::factory()->create([
        'category_id' => $category->id,
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
    ]);

    expect($article->view_count)->toBe(0);

    $article->incrementViewCount();

    expect($article->fresh()->view_count)->toBe(1);
});

it('uses slug as route key', function () {
    $article = new Article;

    expect($article->getRouteKeyName())->toBe('slug');
});

it('soft deletes articles', function () {
    $category = Category::factory()->create();
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $article = Article::factory()->create([
        'category_id' => $category->id,
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
    ]);

    $article->delete();

    expect(Article::count())->toBe(0);
    expect(Article::withTrashed()->count())->toBe(1);
});

it('casts metadata to array', function () {
    $category = Category::factory()->create();
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $article = Article::factory()->create([
        'category_id' => $category->id,
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
        'metadata' => ['key' => 'value'],
    ]);

    expect($article->fresh()->metadata)->toBe(['key' => 'value']);
});
