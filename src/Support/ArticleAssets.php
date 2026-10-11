<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JeffersonGoncalves\KnowledgeBase\Enums\AssetKind;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleAssetContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;

/**
 * Stores, locates and cleans up the images and files an article owns (see
 * design D2, D3, D7 and D9). Everything that touches article assets goes
 * through here, so the disk layout, the allowed types and the "one folder per
 * article" rule live in one place.
 */
class ArticleAssets
{
    /**
     * The configured disk for new assets.
     */
    public function disk(): string
    {
        return (string) config('knowledge-base.attachments.disk', 'public');
    }

    /**
     * The configured root directory, without a trailing slash.
     */
    public function directory(): string
    {
        return trim((string) config('knowledge-base.attachments.directory', 'knowledge-base'), '/');
    }

    /**
     * Store an image or file for an article.
     *
     * The default language's assets are the article's shared assets, so a
     * default-language scope is stored as shared (design D7). An unconfigured
     * language is rejected.
     *
     * @param  UploadedFile|string  $file  an upload, or the path to a local file
     *
     * @throws InvalidArgumentException when the language is not configured
     * @throws ValidationException when the file's type or size is not allowed
     */
    public function store(Model&ArticleContract $article, UploadedFile|string $file, AssetKind $kind, ?string $locale = null): ArticleAssetContract
    {
        $scope = $this->scopeLocale($locale);
        $source = $file instanceof UploadedFile ? $file : new File($file);

        $this->validate($source, $kind);

        $mime = (string) $source->getMimeType();
        $originalName = $file instanceof UploadedFile
            ? $file->getClientOriginalName()
            : $source->getFilename();

        $disk = $this->disk();
        $directory = $this->directoryFor($article, $scope);
        $path = $directory.'/'.$this->fileName($originalName, $mime, $source->guessExtension());

        if ($file instanceof UploadedFile) {
            Storage::disk($disk)->putFileAs($directory, $file, basename($path));
        } else {
            Storage::disk($disk)->put($path, (string) file_get_contents($source->getRealPath()));
        }

        [$width, $height] = $this->dimensions($source, $kind);

        $assetClass = ModelResolver::articleAsset();

        /** @var Model&ArticleAssetContract $asset */
        $asset = $assetClass::create([
            'article_id' => $article->getKey(),
            'kind' => $kind,
            'locale' => $scope,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mime,
            'size' => (int) $source->getSize(),
            'width' => $width,
            'height' => $height,
        ]);

        return $asset;
    }

    /**
     * The current URL of an asset, built from its own disk and path (design
     * D5). Never stored in content: content refers to the asset by its uuid.
     */
    public function url(Model&ArticleAssetContract $asset): string
    {
        return Storage::disk((string) $asset->getAttribute('disk'))->url((string) $asset->getAttribute('path'));
    }

    /**
     * Remove an asset's file and row. Deleting an asset that is still used is
     * allowed; callers show its usage first (design D7).
     */
    public function delete(Model&ArticleAssetContract $asset): void
    {
        Storage::disk((string) $asset->getAttribute('disk'))->delete((string) $asset->getAttribute('path'));

        $asset->delete();
    }

    /**
     * Remove every asset of one language version (design D7).
     */
    public function deleteForLocale(Model&ArticleContract $article, string $locale): void
    {
        foreach ($this->assetsOf($article) as $asset) {
            if ($asset->getAttribute('locale') === $locale) {
                $this->delete($asset);
            }
        }
    }

    /**
     * Remove every file of an article and its folder on the configured disk,
     * used when an article is force-deleted (design D7). The rows themselves
     * cascade with the article.
     */
    public function purge(Model&ArticleContract $article): void
    {
        foreach ($this->assetsOf($article) as $asset) {
            Storage::disk((string) $asset->getAttribute('disk'))->delete((string) $asset->getAttribute('path'));
        }

        Storage::disk($this->disk())->deleteDirectory($this->articleDirectory($article));
    }

    /**
     * @return iterable<int, Model&ArticleAssetContract>
     */
    protected function assetsOf(Model&ArticleContract $article): iterable
    {
        $assetClass = ModelResolver::articleAsset();

        return $assetClass::query()->where('article_id', $article->getKey())->get();
    }

    /**
     * The stored scope: null for shared, the language for a non-default one.
     */
    protected function scopeLocale(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        if (! Locales::isConfigured($locale)) {
            throw new InvalidArgumentException("Locale [{$locale}] is not configured.");
        }

        // The default language's assets are the article's shared assets.
        return $locale === Locales::default() ? null : $locale;
    }

    /**
     * Validate the file's detected type and size for its kind (design D9).
     */
    protected function validate(SymfonyFile $file, AssetKind $kind): void
    {
        $group = $kind === AssetKind::Image ? 'images' : 'files';

        /** @var list<string> $accepted */
        $accepted = (array) config("knowledge-base.attachments.{$group}.accepted_types", []);
        $maxSize = (int) config("knowledge-base.attachments.{$group}.max_size", 0);

        $rules = array_values(array_filter([
            'required',
            'file',
            $maxSize > 0 ? 'max:'.$maxSize : null,
            $accepted !== [] ? 'mimetypes:'.implode(',', $accepted) : null,
        ]));

        $validator = Validator::make(['file' => $file], ['file' => $rules]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    protected function dimensions(SymfonyFile $file, AssetKind $kind): array
    {
        if ($kind !== AssetKind::Image) {
            return [null, null];
        }

        $size = @getimagesize($file->getRealPath());

        if ($size === false) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * A readable name from the original, plus a short random code and the
     * extension of the detected type (design D3).
     */
    protected function fileName(string $originalName, string $mime, ?string $guessedExtension): string
    {
        $base = Str::limit(Str::slug(pathinfo($originalName, PATHINFO_FILENAME)), 60, '');

        if ($base === '') {
            $base = 'file';
        }

        $extension = $this->extensionFor($mime) ?? $guessedExtension ?? 'bin';

        return $base.'-'.substr(bin2hex(random_bytes(3)), 0, 6).'.'.$extension;
    }

    protected function extensionFor(string $mime): ?string
    {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
        ];

        return $extensions[$mime] ?? null;
    }

    /**
     * `{directory}/articles/{article uuid}`, named after the uuid so it stays
     * stable when the title or slug changes (design D3).
     */
    protected function articleDirectory(Model&ArticleContract $article): string
    {
        return $this->directory().'/articles/'.(string) $article->getAttribute('uuid');
    }

    protected function directoryFor(Model&ArticleContract $article, ?string $locale): string
    {
        return $this->articleDirectory($article).'/'.($locale ?? 'shared');
    }
}
