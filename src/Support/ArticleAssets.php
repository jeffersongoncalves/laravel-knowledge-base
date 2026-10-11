<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use DOMDocument;
use DOMElement;
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
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\TranslatableContract;
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
    public function store(Model&ArticleContract $article, UploadedFile|string $file, AssetKind $kind, ?string $locale = null): Model&ArticleAssetContract
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
     * Turn article content into displayable HTML with every asset reference
     * pointing at the asset's current URL (design D4 and D5).
     *
     * Images carry Filament's own `data-id`; file links are recognised by their
     * asset path, because Filament's link mark keeps only `href` and `target`
     * (the D4 spike). A `data-kb-asset` attribute is honoured when present.
     *
     * For public readers a reference to an asset that no longer exists (or
     * belongs to another article) is dropped (images) or unlinked to plain text
     * (file links). Content without an asset reference is left untouched.
     */
    public function resolve(string $html, Model&ArticleContract $article, bool $forPublic = false): string
    {
        if ($html === '' || (! str_contains($html, '<img') && ! str_contains($html, '<a'))) {
            return $html;
        }

        $index = $this->assetIndex($article);

        return $this->transform($html, function (DOMElement $root) use ($index, $article, $forPublic): void {
            foreach (iterator_to_array($root->getElementsByTagName('img')) as $image) {
                $id = trim($image->getAttribute('data-id'));

                if ($id === '') {
                    continue; // content from before assets: leave it as it is
                }

                $asset = $index['byUuid'][$id] ?? null;

                if ($asset instanceof Model) {
                    $image->setAttribute('src', $this->url($asset));

                    continue;
                }

                if ($forPublic) {
                    $image->parentNode?->removeChild($image);
                }
            }

            foreach (iterator_to_array($root->getElementsByTagName('a')) as $link) {
                $reference = $this->linkReference($link, $index, $article);

                if ($reference === null) {
                    continue; // an ordinary link, or content from before assets
                }

                if ($reference['asset'] instanceof Model) {
                    $link->setAttribute('href', $this->url($reference['asset']));

                    continue;
                }

                if ($forPublic) {
                    $this->unlink($link);
                }
            }
        });
    }

    /**
     * The languages of its article that currently use an asset, based on the
     * current content of every version (design D6). The version history is not
     * scanned.
     *
     * @return list<string>
     */
    public function usage(Model&ArticleAssetContract $asset): array
    {
        $uuid = (string) $asset->getAttribute('uuid');
        $article = $asset->article()->first();

        if (! $article instanceof Model || ! $article instanceof ArticleContract) {
            return [];
        }

        $index = $this->assetIndex($article);
        $used = [];

        foreach ($this->currentVersions($article) as $locale => $content) {
            if (in_array($uuid, $this->scan($content, $index, $article)['used'], true)) {
                $used[] = $locale;
            }
        }

        return $used;
    }

    /**
     * The references in an article's current content that point at no existing
     * asset of the article, per language (design D6). Empty again as soon as
     * the content no longer contains them.
     *
     * @return array<string, list<string>>
     */
    public function brokenReferences(Model&ArticleContract $article): array
    {
        $broken = [];
        $index = $this->assetIndex($article);

        foreach ($this->currentVersions($article) as $locale => $content) {
            $missing = $this->scan($content, $index, $article)['missing'];

            if ($missing !== []) {
                $broken[$locale] = $missing;
            }
        }

        return $broken;
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
        // Remove the rows explicitly as well as their files: the foreign-key
        // cascade covers the rows on databases that enforce it, but deleting
        // them here keeps the behaviour identical everywhere.
        foreach ($this->assetsOf($article) as $asset) {
            $this->delete($asset);
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
     * The article's assets indexed by uuid (for images) and by stored path (for
     * the path-based file links).
     *
     * @return array{
     *     byUuid: array<string, Model&ArticleAssetContract>,
     *     byPath: array<string, Model&ArticleAssetContract>
     * }
     */
    protected function assetIndex(Model&ArticleContract $article): array
    {
        $byUuid = [];
        $byPath = [];

        foreach ($this->assetsOf($article) as $asset) {
            $byUuid[(string) $asset->getAttribute('uuid')] = $asset;
            $byPath[(string) $asset->getAttribute('path')] = $asset;
        }

        return ['byUuid' => $byUuid, 'byPath' => $byPath];
    }

    /**
     * The current content of every language version, keyed by locale (design
     * D6). The article's own row carries the default-language content.
     *
     * @return array<string, string>
     */
    protected function currentVersions(Model&ArticleContract $article): array
    {
        $versions = [Locales::default() => (string) $article->getAttribute('content')];

        if ($article instanceof TranslatableContract) {
            foreach ($article->translations()->get() as $translation) {
                $versions[(string) $translation->getAttribute('locale')] = (string) $translation->getAttribute('content');
            }
        }

        return $versions;
    }

    /**
     * The asset references in a piece of content: the uuids that resolve to an
     * asset of the article, and the identifiers that resolve to nothing.
     *
     * @param  array{
     *     byUuid: array<string, Model&ArticleAssetContract>,
     *     byPath: array<string, Model&ArticleAssetContract>
     * }  $index
     * @return array{used: list<string>, missing: list<string>}
     */
    protected function scan(string $html, array $index, Model&ArticleContract $article): array
    {
        $used = [];
        $missing = [];

        if ($html === '') {
            return ['used' => [], 'missing' => []];
        }

        $this->transform($html, function (DOMElement $root) use ($index, $article, &$used, &$missing): void {
            foreach (iterator_to_array($root->getElementsByTagName('img')) as $image) {
                $id = trim($image->getAttribute('data-id'));

                if ($id === '') {
                    continue;
                }

                $asset = $index['byUuid'][$id] ?? null;

                if ($asset instanceof Model) {
                    $used[] = (string) $asset->getAttribute('uuid');
                } else {
                    $missing[] = $id;
                }
            }

            foreach (iterator_to_array($root->getElementsByTagName('a')) as $link) {
                $reference = $this->linkReference($link, $index, $article);

                if ($reference === null) {
                    continue;
                }

                if ($reference['asset'] instanceof Model) {
                    $used[] = (string) $reference['asset']->getAttribute('uuid');
                } else {
                    $missing[] = $reference['identifier'];
                }
            }
        });

        return [
            'used' => array_values(array_unique($used)),
            'missing' => array_values(array_unique($missing)),
        ];
    }

    /**
     * Which asset a link points at, or null when it is an ordinary link.
     *
     * @param  array{
     *     byUuid: array<string, Model&ArticleAssetContract>,
     *     byPath: array<string, Model&ArticleAssetContract>
     * }  $index
     * @return array{asset: (Model&ArticleAssetContract)|null, identifier: string}|null
     */
    protected function linkReference(DOMElement $link, array $index, Model&ArticleContract $article): ?array
    {
        $identifier = trim($link->getAttribute('data-kb-asset'));

        if ($identifier !== '') {
            return ['asset' => $index['byUuid'][$identifier] ?? null, 'identifier' => $identifier];
        }

        $path = $this->relativeAssetPath($link->getAttribute('href'), $article);

        if ($path !== null) {
            return ['asset' => $index['byPath'][$path] ?? null, 'identifier' => $path];
        }

        return null;
    }

    /**
     * The asset path inside the article's folder when the URL points into it,
     * otherwise null (design D4 fallback).
     */
    protected function relativeAssetPath(string $href, Model&ArticleContract $article): ?string
    {
        if ($href === '') {
            return null;
        }

        $path = urldecode((string) strtok($href, '?#'));

        $needle = $this->directory().'/articles/'.(string) $article->getAttribute('uuid').'/';
        $position = strpos($path, $needle);

        if ($position === false) {
            return null;
        }

        return substr($path, $position);
    }

    /**
     * Run a callback over the nodes of a wrapped document and return the
     * serialised content, using the same UTF-8 handling as the demo's
     * ArticleContent (design D5).
     *
     * @param  callable(DOMElement): void  $callback
     */
    protected function transform(string $html, callable $callback): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="kb-assets-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('kb-assets-root');

        if (! $root instanceof DOMElement) {
            return $html;
        }

        $callback($root);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return $output;
    }

    /**
     * Replace a link with its own text and children.
     */
    protected function unlink(DOMElement $link): void
    {
        $parent = $link->parentNode;

        if ($parent === null) {
            return;
        }

        while ($link->firstChild !== null) {
            $parent->insertBefore($link->firstChild, $link);
        }

        $parent->removeChild($link);
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
