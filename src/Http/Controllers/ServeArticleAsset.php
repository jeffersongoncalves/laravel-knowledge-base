<?php

namespace JeffersonGoncalves\KnowledgeBase\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\KnowledgeBase\Support\ArticleAssets;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an article's image or file from the disk it was stored on, checking
 * the article's visibility on every request (see the serve-private-assets
 * design D1-D3). An asset of a publicly visible article is served to anyone;
 * any other only with a valid signature. Everything else is a 404, so the
 * response never reveals that a file exists.
 */
class ServeArticleAsset
{
    public function __invoke(Request $request, ArticleAssets $assets, string $asset): StreamedResponse
    {
        $assetClass = ModelResolver::articleAsset();
        $record = $assetClass::query()->where('uuid', $asset)->first();

        if (! $record instanceof Model) {
            abort(404);
        }

        $public = $assets->isServedPublicly($record);

        if (! $public && ! $request->hasValidSignature()) {
            abort(404);
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) $record->getAttribute('disk'));
        $path = (string) $record->getAttribute('path');
        $mime = (string) $record->getAttribute('mime_type');

        $headers = [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $public
                ? 'public, max-age='.max(0, (int) config('knowledge-base.attachments.route.cache_max_age', 3600))
                : 'private, no-store',
        ];

        // SVG is off by default because it can carry scripts; an app that
        // allows it still never runs them when the file is opened directly.
        if ($mime === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, basename($path), $headers);
    }
}
