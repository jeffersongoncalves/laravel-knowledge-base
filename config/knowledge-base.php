<?php

use JeffersonGoncalves\KnowledgeBase\Models\Article;
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
    | Where files that editors add to article content (such as images in the
    | rich text editor) are stored. Everything lives under one directory, so
    | the knowledge base's files stay together and apart from the rest of
    | the app. The disk must be publicly readable, since readers see these
    | files: use 'public' (run `php artisan storage:link` once) or a cloud
    | disk such as 's3'.
    |
    | Changing the directory later does not move existing files, and content
    | that already points to them keeps the old location.
    |
    */
    'attachments' => [
        'disk' => env('KB_ATTACHMENTS_DISK', 'public'),
        'directory' => env('KB_ATTACHMENTS_DIRECTORY', 'knowledge-base/attachments'),
    ],
];
