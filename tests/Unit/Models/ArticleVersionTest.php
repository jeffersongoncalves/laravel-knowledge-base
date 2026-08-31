<?php

use Illuminate\Support\Facades\Schema;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleVersion;
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

it('uses the article_versions table with prefix', function () {
    $version = new ArticleVersion;

    expect($version->getTable())->toBe('kb_article_versions');
});

it('does not use automatic timestamps', function () {
    expect((new ArticleVersion)->timestamps)->toBeFalse();
});

it('belongs to an article', function () {
    $version = $this->article->versions()->create([
        'version_number' => 1,
        'title' => $this->article->title,
        'content' => $this->article->content,
        'editor_type' => $this->user->getMorphClass(),
        'editor_id' => $this->user->id,
        'created_at' => now(),
    ]);

    expect($version->article->id)->toBe($this->article->id);
});

it('morphs to an editor', function () {
    $version = $this->article->versions()->create([
        'version_number' => 1,
        'title' => $this->article->title,
        'content' => $this->article->content,
        'editor_type' => $this->user->getMorphClass(),
        'editor_id' => $this->user->id,
        'created_at' => now(),
    ]);

    expect($version->editor->id)->toBe($this->user->id);
});

it('casts version number to integer', function () {
    $version = $this->article->versions()->create([
        'version_number' => '5',
        'title' => $this->article->title,
        'content' => $this->article->content,
        'editor_type' => $this->user->getMorphClass(),
        'editor_id' => $this->user->id,
        'created_at' => now(),
    ]);

    expect($version->fresh()->version_number)->toBe(5);
});

it('enforces unique version number per article', function () {
    $unique = collect(Schema::getIndexes('kb_article_versions'))
        ->contains(fn ($index) => $index['unique'] && $index['columns'] === ['article_id', 'version_number']);

    expect($unique)->toBeTrue();
});
