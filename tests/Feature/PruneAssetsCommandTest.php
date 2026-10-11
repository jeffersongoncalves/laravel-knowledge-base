<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\KnowledgeBase\Enums\AssetKind;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleAsset;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleAssetContract;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;
use JeffersonGoncalves\KnowledgeBase\Support\ArticleAssets;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'knowledge-base.locales' => ['en', 'de'],
        'knowledge-base.default_locale' => 'en',
        'knowledge-base.attachments' => [
            'disk' => 'public',
            'directory' => 'knowledge-base',
            'images' => ['accepted_types' => ['image/jpeg', 'image/png', 'image/webp'], 'max_size' => 5120],
            'files' => ['accepted_types' => ['application/pdf'], 'max_size' => 10240],
        ],
    ]);

    Storage::fake('public');

    User::createTable();
    $this->assets = app(ArticleAssets::class);
    $this->user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $this->category = Category::factory()->create(['name' => 'Plant care', 'slug' => 'plant-care']);

    /** @var Article $article */
    $article = app(KnowledgeBaseService::class)->createArticle([
        'category_id' => $this->category->id,
        'title' => 'Fern leaf care',
        'slug' => 'fern-leaf-care',
        'content' => 'How to care for a fern.',
    ], $this->user);

    $this->article = $article;
});

function pruneAsset(Article $article): ArticleAssetContract
{
    return test()->assets->store($article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);
}

it('removes a file that no asset record knows', function () {
    $asset = pruneAsset($this->article);
    $stray = 'knowledge-base/articles/'.$this->article->uuid.'/shared/stray.jpg';
    Storage::disk('public')->put($stray, 'copied by hand');
    $this->travel(2)->hours();

    $this->artisan('kb:prune-assets')
        ->expectsOutputToContain('Stray file:')
        ->assertSuccessful();

    Storage::disk('public')->assertMissing($stray);
    Storage::disk('public')->assertExists($asset->getAttribute('path'));
});

it('lists what it would remove on a dry run and removes nothing', function () {
    $stray = 'knowledge-base/articles/'.$this->article->uuid.'/shared/stray.jpg';
    Storage::disk('public')->put($stray, 'copied by hand');
    $this->travel(2)->hours();

    $this->artisan('kb:prune-assets', ['--dry-run' => true])
        ->expectsOutputToContain('Stray file:')
        ->expectsOutputToContain('Would remove')
        ->assertSuccessful();

    Storage::disk('public')->assertExists($stray);
});

it('keeps an unused but valid asset', function () {
    $asset = pruneAsset($this->article);

    $this->artisan('kb:prune-assets')->assertSuccessful();

    Storage::disk('public')->assertExists($asset->getAttribute('path'));
    expect(ArticleAsset::query()->whereKey($asset->getKey())->exists())->toBeTrue();
});

it('reports an asset record whose file is missing', function () {
    $asset = pruneAsset($this->article);
    Storage::disk('public')->delete($asset->getAttribute('path'));

    $this->artisan('kb:prune-assets')
        ->expectsOutputToContain('Missing file for asset '.$asset->getAttribute('uuid'))
        ->assertSuccessful();
});

it('removes the folder of an article that no longer exists', function () {
    $orphan = 'knowledge-base/articles/00000000-0000-0000-0000-000000000000/shared/old.jpg';
    Storage::disk('public')->put($orphan, 'orphaned');
    $this->travel(2)->hours();

    $this->artisan('kb:prune-assets')->assertSuccessful();

    Storage::disk('public')->assertMissing($orphan);
});

it('keeps a file written in the past hour, which may be an upload still in progress', function () {
    $stray = 'knowledge-base/articles/'.$this->article->uuid.'/shared/uploading.jpg';
    $orphan = 'knowledge-base/articles/00000000-0000-0000-0000-000000000000/shared/new.jpg';
    Storage::disk('public')->put($stray, 'not recorded yet');
    Storage::disk('public')->put($orphan, 'article not visible yet');

    $this->artisan('kb:prune-assets')
        ->expectsOutputToContain('Nothing to prune.')
        ->assertSuccessful();

    Storage::disk('public')->assertExists($stray);
    Storage::disk('public')->assertExists($orphan);
});
