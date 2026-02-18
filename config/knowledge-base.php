<?php

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
        'article' => \JeffersonGoncalves\KnowledgeBase\Models\Article::class,
        'category' => \JeffersonGoncalves\KnowledgeBase\Models\Category::class,
        'article_version' => \JeffersonGoncalves\KnowledgeBase\Models\ArticleVersion::class,
        'article_feedback' => \JeffersonGoncalves\KnowledgeBase\Models\ArticleFeedback::class,
        'article_relation' => \JeffersonGoncalves\KnowledgeBase\Models\ArticleRelation::class,
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
    | Search
    |--------------------------------------------------------------------------
    |
    | Search engine configuration. Currently supports 'database' for
    | LIKE-based search. Future: 'scout' for Laravel Scout integration.
    |
    */
    'search_engine' => 'database',
    'search_results_limit' => 20,
];
