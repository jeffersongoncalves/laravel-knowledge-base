<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\KnowledgeBase\Enums\AssetKind;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
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

    // Signed URLs of non-public articles depend on the clock.
    $this->freezeTime();

    User::createTable();
    $this->assets = app(ArticleAssets::class);
    $this->user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $this->category = Category::factory()->create(['name' => 'Plant care', 'slug' => 'plant-care']);
    $this->article = assetResolutionArticle('fern-leaf-care');
});

function assetResolutionArticle(string $slug): Article
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

function assetResolutionPdf(string $name = 'warranty.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
}

function imageMarkup(string $uuid, string $src = 'https://old.example.com/storage/old.jpg'): string
{
    return '<p>Have a look: <img data-id="'.$uuid.'" src="'.$src.'" alt="A fern"></p>';
}

describe('Content refers to assets, never to URLs', function () {
    it('shows an image from the current address whatever the stored src says', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);

        $resolved = $this->assets->resolve(imageMarkup($asset->getAttribute('uuid')), $this->article);

        expect($resolved)->toContain('src="'.e($this->assets->url($asset)).'"')
            ->and($resolved)->not->toContain('old.example.com')
            ->and($resolved)->toContain('data-id="'.$asset->getAttribute('uuid').'"');
    });

    it('keeps working from the disk an asset was stored on when the configured disk changes', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);

        Storage::fake('archive');
        config([
            'knowledge-base.attachments.disk' => 'archive',
            // Disk URLs, to see which disk the URL comes from.
            'knowledge-base.attachments.route.enabled' => false,
        ]);

        $resolved = $this->assets->resolve(imageMarkup($asset->getAttribute('uuid')), $this->article);

        expect($resolved)->toContain('src="'.Storage::disk('public')->url($asset->getAttribute('path')).'"');
    });

    it('leaves content from before assets existed untouched', function () {
        $html = '<p><img src="/img/old-logo.png" alt="Old"></p>';

        expect($this->assets->resolve($html, $this->article))->toBe($html);
    });
});

describe('Resolving content for readers', function () {
    it('resolves a shared image used in the German version', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);

        $this->article->translations()->create([
            'locale' => 'de',
            'title' => 'Farnpflege',
            'slug' => 'farnpflege',
            'content' => imageMarkup($asset->getAttribute('uuid')),
        ]);

        $resolved = $this->assets->resolve(imageMarkup($asset->getAttribute('uuid')), $this->article->fresh(), forPublic: true);

        expect($resolved)->toContain('src="'.e($this->assets->url($asset)).'"');
    });

    it('leaves out an image whose asset was deleted for a public reader', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);
        $html = imageMarkup($asset->getAttribute('uuid'));

        $this->assets->delete($asset);

        $resolved = $this->assets->resolve($html, $this->article, forPublic: true);

        expect($resolved)->not->toContain('<img')->and($resolved)->not->toContain('old.example.com');
    });

    it('keeps the cached image for the editor when the asset was deleted', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);
        $html = imageMarkup($asset->getAttribute('uuid'));

        $this->assets->delete($asset);

        expect($this->assets->resolve($html, $this->article))->toContain('<img');
    });

    it('treats an asset of another article as missing', function () {
        $other = assetResolutionArticle('other-article');
        $asset = $this->assets->store($other, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);

        $resolved = $this->assets->resolve(imageMarkup($asset->getAttribute('uuid')), $this->article, forPublic: true);

        expect($resolved)->not->toContain('<img');
    });

    it('rewrites a file link found by its path and unlinks it for readers when its asset is gone', function () {
        $asset = $this->assets->store($this->article, assetResolutionPdf(), AssetKind::File);
        $href = Storage::disk('public')->url($asset->getAttribute('path'));
        $html = '<p><a href="'.$href.'">warranty.pdf, 240 KB</a></p>';

        $resolved = $this->assets->resolve($html, $this->article);

        expect($resolved)->toContain('href="'.e($this->assets->url($asset)).'"');

        $this->assets->delete($asset);

        $unlinked = $this->assets->resolve($html, $this->article, forPublic: true);

        expect($unlinked)->not->toContain('<a')->toContain('warranty.pdf, 240 KB');
    });

    it('honours a data-kb-asset attribute on a link when one is present', function () {
        $asset = $this->assets->store($this->article, assetResolutionPdf(), AssetKind::File);
        $html = '<p><a href="https://old.example.com/x.pdf" data-kb-asset="'.$asset->getAttribute('uuid').'">warranty.pdf</a></p>';

        $resolved = $this->assets->resolve($html, $this->article);

        expect($resolved)->toContain('href="'.e($this->assets->url($asset)).'"')
            ->and($resolved)->not->toContain('old.example.com');
    });
});

describe('Where an asset is used', function () {
    it('reports every language that uses a shared image', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);

        $this->article->translations()->create([
            'locale' => 'de',
            'title' => 'Farnpflege',
            'slug' => 'farnpflege',
            'content' => imageMarkup($asset->getAttribute('uuid')),
        ]);

        $this->article->translation('en')->update(['content' => imageMarkup($asset->getAttribute('uuid'))]);

        expect($this->assets->usage($asset))->toBe(['en', 'de']);
    });

    it('reports no usage for an unused asset', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);

        expect($this->assets->usage($asset))->toBe([]);
    });

    it('ignores a reference that only lives in the version history', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);

        $this->article->versions()->create([
            'version_number' => 99,
            'locale' => 'en',
            'title' => 'Fern leaf care',
            'content' => imageMarkup($asset->getAttribute('uuid')),
            'editor_type' => $this->user->getMorphClass(),
            'editor_id' => $this->user->getKey(),
            'change_notes' => 'Old version',
            'created_at' => now(),
        ]);

        expect($this->assets->usage($asset))->toBe([]);
    });
});

describe('Broken references', function () {
    it('reports an image deleted while the German version still uses it', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);

        $this->article->translations()->create([
            'locale' => 'de',
            'title' => 'Farnpflege',
            'slug' => 'farnpflege',
            'content' => imageMarkup($asset->getAttribute('uuid')),
        ]);

        $uuid = (string) $asset->getAttribute('uuid');
        $this->assets->delete($asset);

        expect($this->assets->brokenReferences($this->article->fresh()))->toBe(['de' => [$uuid]]);
    });

    it('reports no broken references after the reference is removed', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);

        $this->article->translations()->create([
            'locale' => 'de',
            'title' => 'Farnpflege',
            'slug' => 'farnpflege',
            'content' => imageMarkup($asset->getAttribute('uuid')),
        ]);

        $this->assets->delete($asset);

        $this->article->translation('de')->update(['content' => '<p>No image any more.</p>']);

        expect($this->assets->brokenReferences($this->article->fresh()))->toBe([]);
    });

    it('reports a broken reference when an old version is restored', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);
        $reference = imageMarkup($asset->getAttribute('uuid'));

        $this->article->translation('en')->update(['content' => $reference]);
        $this->article->translation('en')->update(['content' => '<p>Rewritten without the image.</p>']);

        $uuid = (string) $asset->getAttribute('uuid');
        $this->assets->delete($asset);

        // Restoring the earlier version puts the reference back.
        $this->article->translation('en')->update(['content' => $reference]);

        expect($this->assets->brokenReferences($this->article->fresh()))->toBe(['en' => [$uuid]]);
    });
});
