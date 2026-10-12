<?php

use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleAsset;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleFeedback;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleRelation;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleTranslation;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleVersion;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Models\CategoryTranslation;
use JeffersonGoncalves\KnowledgeBase\Models\Collection;
use JeffersonGoncalves\KnowledgeBase\Models\CollectionTranslation;

return [
    /*
    |--------------------------------------------------------------------------
    | Table Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix applied to all tables created by the package to avoid
    | collision with existing application tables.
    | Set to null to use table names without a prefix.
    |
    */
    'table_prefix' => 'kb_',

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Models used by the package. Can be overridden to extend the default
    | behavior. Custom models must implement the corresponding contract
    | interface (see src/Models/Contracts/).
    |
    */
    'models' => [
        'article' => Article::class,
        'category' => Category::class,
        'article_version' => ArticleVersion::class,
        'article_feedback' => ArticleFeedback::class,
        'article_relation' => ArticleRelation::class,
        'article_translation' => ArticleTranslation::class,
        'category_translation' => CategoryTranslation::class,
        'collection' => Collection::class,
        'collection_translation' => CollectionTranslation::class,
        'article_asset' => ArticleAsset::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge Base Settings
    |--------------------------------------------------------------------------
    |
    | General settings for the knowledge base functionality.
    |
    */
    'default_visibility' => 'public',
    'versioning_enabled' => true,
    'feedback_enabled' => true,
    'track_views' => true,

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | The languages the knowledge base serves. Articles and categories have at
    | most one version per configured language. `default_locale` is the
    | authoritative language new content defaults to and the language existing
    | content is tagged with during the upgrade; it must be part of `locales`.
    |
    | `missing_translation` decides what a reader gets when a language has no
    | readable version: 'fallback' serves the authoritative version, 'hide'
    | serves nothing. Any other value is rejected.
    |
    | `search_languages` maps a locale to the PostgreSQL text-search
    | configuration used to stem it; unknown locales use 'simple'. MySQL
    | ignores it.
    |
    */
    'locales' => ['en'],
    'default_locale' => 'en',
    'missing_translation' => 'fallback',
    'search_languages' => [
        'en' => 'english',
        'de' => 'german',
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Search engine configuration. Currently supports 'database' for
    | LIKE-based search. Future: 'scout' for Laravel Scout integration.
    |
    */
    'search_engine' => 'database',
    'search_results_limit' => 20,

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Where the images and files that belong to articles are stored. Every
    | article keeps its own folder under `directory/articles/{uuid}` on `disk`,
    | so a backup or restore of one article is a folder copy. The default
    | 'local' disk is private: readers get the files through `route` below,
    | which checks the article's visibility on every request. Images and
    | files of internal articles are only served through signed URLs that
    | expire after `signed_url_minutes` (rounded up to the end of the hour).
    |
    | Changing the disk or directory later does not move existing files:
    | assets remember the disk and path they were stored on. Run
    | `php artisan kb:move-assets` to move them to the configured disk.
    |
    | Set `route.enabled` to false to link to the disk's own URLs instead
    | (then the disk must be public, and so are internal articles' files).
    |
    | `images` and `files` each accept only the listed MIME types (checked on
    | the file's contents, not its name) up to `max_size` kilobytes. SVG is
    | deliberately not offered: it can carry scripts. Add 'image/avif' or
    | 'image/gif' here to accept them (see the README).
    |
    */
    'attachments' => [
        'disk' => env('KB_ATTACHMENTS_DISK', 'local'),
        'directory' => env('KB_ATTACHMENTS_DIRECTORY', 'knowledge-base'),
        'route' => [
            'enabled' => (bool) env('KB_ATTACHMENTS_ROUTE', true),
            'prefix' => env('KB_ATTACHMENTS_ROUTE_PREFIX', 'knowledge-base/assets'),
            'middleware' => [],
            'signed_url_minutes' => (int) env('KB_ATTACHMENTS_SIGNED_URL_MINUTES', 30),
            'cache_max_age' => (int) env('KB_ATTACHMENTS_CACHE_MAX_AGE', 3600),
        ],
        'images' => [
            'accepted_types' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_size' => (int) env('KB_ATTACHMENTS_IMAGES_MAX_SIZE', 5120),
        ],
        'files' => [
            'accepted_types' => ['application/pdf'],
            'max_size' => (int) env('KB_ATTACHMENTS_FILES_MAX_SIZE', 10240),
        ],
    ],
];
