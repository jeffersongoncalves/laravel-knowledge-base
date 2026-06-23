<?php

use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleFeedback;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    User::createTable();
    $this->category = Category::factory()->create();
    $this->user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $this->article = Article::factory()->create([
        'category_id' => $this->category->id,
        'author_type' => $this->user->getMorphClass(),
        'author_id' => $this->user->id,
    ]);
});

it('uses the article_feedback table with prefix', function () {
    $feedback = new ArticleFeedback;

    expect($feedback->getTable())->toBe('kb_article_feedback');
});

it('does not use automatic timestamps', function () {
    expect((new ArticleFeedback)->timestamps)->toBeFalse();
});

it('casts is_helpful to boolean', function () {
    $feedback = $this->article->feedback()->create([
        'is_helpful' => 1,
        'created_at' => now(),
    ]);

    expect($feedback->fresh()->is_helpful)->toBeTrue();
});

it('belongs to an article', function () {
    $feedback = $this->article->feedback()->create([
        'is_helpful' => true,
        'created_at' => now(),
    ]);

    expect($feedback->article->id)->toBe($this->article->id);
});

it('morphs to an optional user', function () {
    $withUser = $this->article->feedback()->create([
        'user_type' => $this->user->getMorphClass(),
        'user_id' => $this->user->id,
        'is_helpful' => true,
        'created_at' => now(),
    ]);

    $anonymous = $this->article->feedback()->create([
        'is_helpful' => false,
        'created_at' => now(),
    ]);

    expect($withUser->user->id)->toBe($this->user->id);
    expect($anonymous->user)->toBeNull();
});
