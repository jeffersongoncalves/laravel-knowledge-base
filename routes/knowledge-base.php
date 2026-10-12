<?php

use Illuminate\Support\Facades\Route;
use JeffersonGoncalves\KnowledgeBase\Http\Controllers\ServeArticleAsset;
use JeffersonGoncalves\KnowledgeBase\Support\ArticleAssets;

// Article images and files, served with the article's visibility (see the
// `attachments.route` config). The file name is only there for readable URLs
// and downloads; the asset is looked up by its uuid.
if (config('knowledge-base.attachments.route.enabled', true)) {
    Route::get(trim((string) config('knowledge-base.attachments.route.prefix', 'knowledge-base/assets'), '/').'/{asset}/{name?}', ServeArticleAsset::class)
        ->middleware((array) config('knowledge-base.attachments.route.middleware', []))
        ->whereUuid('asset')
        ->name(ArticleAssets::ROUTE);
}
