<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
            'images' => [
                'accepted_types' => ['image/jpeg', 'image/png', 'image/webp'],
                'max_size' => 5120,
            ],
            'files' => [
                'accepted_types' => ['application/pdf'],
                'max_size' => 10240,
            ],
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

function pdfBytes(): string
{
    return "%PDF-1.4\n"
        ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
        ."trailer<</Root 1 0 R>>\n%%EOF";
}

function fakePdf(string $name = 'warranty.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, pdfBytes());
}

/**
 * A real UploadedFile whose detected MIME type comes from its content, not its
 * name, like a genuine upload (Illuminate\Http\Testing\File guesses the type
 * from the name, which would hide a mismatched extension).
 */
function realUpload(string $name, string $content): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'kb-asset-test-');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function fakeAvif(string $name = 'fern.avif'): UploadedFile
{
    // A minimal AVIF file header: enough for the MIME detector to call it an
    // AVIF image, but not decodable, so getimagesize() cannot read dimensions.
    $bytes = pack('N', 24).'ftypavif'.pack('N', 0).'avifmif1';

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

describe('Assets belong to an article', function () {
    it('stores a shared image when no language is given', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg', 40, 30), AssetKind::Image);

        expect($asset->isShared())->toBeTrue()
            ->and($asset->getAttribute('locale'))->toBeNull()
            ->and($asset->getAttribute('article_id'))->toBe($this->article->id)
            ->and($asset->kind())->toBe(AssetKind::Image)
            ->and($asset->getAttribute('mime_type'))->toBe('image/jpeg')
            ->and($asset->getAttribute('width'))->toBe(40)
            ->and($asset->getAttribute('height'))->toBe(30);

        Storage::disk('public')->assertExists($asset->getAttribute('path'));
    });

    it('stores a language-specific image for a non-default language', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('farn.jpg'), AssetKind::Image, 'de');

        expect($asset->isShared())->toBeFalse()
            ->and($asset->getAttribute('locale'))->toBe('de')
            ->and($asset->getAttribute('path'))->toContain('/de/');
    });

    it('stores an asset for the default language as shared', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image, 'en');

        expect($asset->isShared())->toBeTrue()
            ->and($asset->getAttribute('locale'))->toBeNull()
            ->and($asset->getAttribute('path'))->toContain('/shared/');
    });

    it('rejects a language that is not configured', function () {
        expect(fn () => $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image, 'it'))
            ->toThrow(InvalidArgumentException::class);

        expect(Storage::disk('public')->allFiles())->toBeEmpty();
    });
});

describe('One folder per article', function () {
    it('keeps two files with the same name under readable, collision-free names', function () {
        $first = $this->assets->store($this->article, UploadedFile::fake()->image('Fern Leaf.JPG'), AssetKind::Image);
        $second = $this->assets->store($this->article, UploadedFile::fake()->image('Fern Leaf.JPG'), AssetKind::Image);

        $firstPath = (string) $first->getAttribute('path');
        $secondPath = (string) $second->getAttribute('path');

        expect($firstPath)->toContain('/shared/')
            ->and($secondPath)->toContain('/shared/')
            ->and(basename($firstPath))->toMatch('/^fern-leaf-[0-9a-f]{6}\.jpg$/')
            ->and(basename($secondPath))->toMatch('/^fern-leaf-[0-9a-f]{6}\.jpg$/')
            ->and($firstPath)->not->toBe($secondPath);

        Storage::disk('public')->assertExists($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    });

    it('keeps the same folder when the title and slug change', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);
        $path = (string) $asset->getAttribute('path');

        $this->article->update(['title' => 'Caring for a fern', 'slug' => 'caring-for-a-fern']);

        expect($path)->toContain('/articles/'.$this->article->uuid.'/')
            ->and(Storage::disk('public')->exists($path))->toBeTrue();
    });

    it('keeps every file of one article under one folder', function () {
        $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);
        $this->assets->store($this->article, UploadedFile::fake()->image('farn.jpg'), AssetKind::Image, 'de');
        $this->assets->store($this->article, fakePdf('warranty.pdf'), AssetKind::File);

        $files = Storage::disk('public')->allFiles('knowledge-base/articles/'.$this->article->uuid);

        expect($files)->toHaveCount(3)
            ->and(collect($files)->filter(fn (string $file): bool => str_contains($file, '/shared/')))->toHaveCount(2)
            ->and(collect($files)->filter(fn (string $file): bool => str_contains($file, '/de/')))->toHaveCount(1);
    });
});

describe('Allowed types and sizes', function () {
    it('rejects a PDF stored as an image', function () {
        expect(fn () => $this->assets->store($this->article, realUpload('document.pdf', pdfBytes()), AssetKind::Image))
            ->toThrow(ValidationException::class);
    });

    it('rejects a PDF renamed to look like an image', function () {
        expect(fn () => $this->assets->store($this->article, realUpload('photo.jpg', pdfBytes()), AssetKind::Image))
            ->toThrow(ValidationException::class);
    });

    it('rejects an image attached as a file when only PDF files are allowed', function () {
        expect(fn () => $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::File))
            ->toThrow(ValidationException::class);
    });

    it('rejects a file larger than the configured maximum', function () {
        expect(fn () => $this->assets->store($this->article, UploadedFile::fake()->image('huge.jpg')->size(6000), AssetKind::Image))
            ->toThrow(ValidationException::class);
    });

    it('stores an AVIF image when its type is enabled and leaves unreadable dimensions empty', function () {
        config(['knowledge-base.attachments.images.accepted_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif']]);

        $asset = $this->assets->store($this->article, fakeAvif(), AssetKind::Image);

        expect($asset->getAttribute('mime_type'))->toBe('image/avif')
            ->and($asset->getAttribute('width'))->toBeNull()
            ->and($asset->getAttribute('height'))->toBeNull()
            ->and($asset->getAttribute('path'))->toEndWith('.avif');

        Storage::disk('public')->assertExists($asset->getAttribute('path'));
    });
});

describe('Asset URLs', function () {
    it('builds the URL from the asset disk and path', function () {
        $asset = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);

        expect($this->assets->url($asset))->toBe(Storage::disk('public')->url($asset->getAttribute('path')));
    });
});

it('creates no asset record when the disk reports a failed write', function () {
    $disk = Mockery::mock(Storage::disk('public'))->makePartial();
    $disk->shouldReceive('putFileAs')->andReturn(false);
    $disk->shouldReceive('put')->andReturn(false);
    Storage::set('public', $disk);

    $local = (string) tempnam(sys_get_temp_dir(), 'kb-asset-test-');
    file_put_contents($local, pdfBytes());

    expect(fn () => $this->assets->store($this->article, fakePdf(), AssetKind::File))->toThrow(RuntimeException::class)
        ->and(fn () => $this->assets->store($this->article, $local, AssetKind::File))->toThrow(RuntimeException::class)
        ->and($this->article->assets()->count())->toBe(0);
});
