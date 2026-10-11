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
        'knowledge-base.missing_translation' => 'fallback',
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
    $this->article = lifecycleArticle('fern-leaf-care');
});

function lifecycleArticle(string $slug): Article
{
    /** @var Article $article */
    $article = app(KnowledgeBaseService::class)->createArticle([
        'category_id' => test()->category->id,
        'title' => 'Fern leaf care',
        'slug' => $slug,
        'content' => 'How to care for a fern.',
    ], test()->user);

    return $article;
}

function lifecycleImage(Article $article, ?string $locale = null): ArticleAssetContract
{
    return test()->assets->store($article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image, $locale);
}

it('removes the file and record and leaves a broken reference when a used asset is deleted', function () {
    $asset = lifecycleImage($this->article, 'de');
    $path = (string) $asset->getAttribute('path');

    $this->article->translations()->create([
        'locale' => 'de',
        'title' => 'Farnpflege',
        'slug' => 'farnpflege',
        'content' => '<img data-id="'.$asset->getAttribute('uuid').'" src="/x.jpg">',
    ]);

    $this->assets->delete($asset);

    Storage::disk('public')->assertMissing($path);
    expect(ArticleAsset::query()->whereKey($asset->getKey())->exists())->toBeFalse()
        ->and($this->assets->brokenReferences($this->article->fresh()))->toHaveKey('de');
});

it('keeps the assets of a soft-deleted article and after it is restored', function () {
    $asset = lifecycleImage($this->article);

    $this->article->delete();

    Storage::disk('public')->assertExists($asset->getAttribute('path'));
    expect(ArticleAsset::query()->whereKey($asset->getKey())->exists())->toBeTrue();

    $this->article->restore();

    Storage::disk('public')->assertExists($asset->getAttribute('path'));
    expect(ArticleAsset::query()->whereKey($asset->getKey())->exists())->toBeTrue();
});

it('removes the folder and records when an article is force-deleted', function () {
    lifecycleImage($this->article);
    lifecycleImage($this->article, 'de');

    $articleId = $this->article->getKey();
    $folder = 'knowledge-base/articles/'.$this->article->uuid;

    expect(Storage::disk('public')->allFiles($folder))->toHaveCount(2);

    $this->article->forceDelete();

    expect(Storage::disk('public')->allFiles($folder))->toBeEmpty()
        ->and(ArticleAsset::query()->where('article_id', $articleId)->count())->toBe(0);
});

it('removes a language version\'s assets and keeps the shared ones', function () {
    $shared = lifecycleImage($this->article);
    $german = lifecycleImage($this->article, 'de');

    $this->article->translations()->create([
        'locale' => 'de',
        'title' => 'Farnpflege',
        'slug' => 'farnpflege',
        'content' => 'Inhalt.',
    ]);

    $this->article->translation('de')->delete();

    Storage::disk('public')->assertMissing($german->getAttribute('path'));
    Storage::disk('public')->assertExists($shared->getAttribute('path'));

    expect(ArticleAsset::query()->whereKey($german->getKey())->exists())->toBeFalse()
        ->and(ArticleAsset::query()->whereKey($shared->getKey())->exists())->toBeTrue();
});

it('removes the file however the asset record is deleted', function () {
    $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern-leaf.jpg'), AssetKind::Image);
    $path = $asset->path;

    Storage::disk('public')->assertExists($path);

    ArticleAsset::query()->whereKey($asset->getKey())->first()->delete();

    Storage::disk('public')->assertMissing($path);
});
