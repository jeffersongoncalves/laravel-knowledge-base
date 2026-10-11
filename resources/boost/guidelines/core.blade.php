## Laravel Knowledge Base

This package provides a complete knowledge base system for Laravel applications with articles, categories, versioning, feedback, and search capabilities.

### Key Concepts

- **Articles**: Content pieces with title, slug, content, SEO fields, and versioning support.
- **Categories**: Hierarchical structure (parent/child) for organizing articles.
- **Versions**: Article version history with editor tracking and change notes.
- **Feedback**: User feedback (helpful/not helpful) with optional comments and IP tracking.
- **Related Articles**: Many-to-many self-referencing relationships between articles.
- **Visibility**: An article is public only when it is published, `public`, and its category and every ancestor are `public`, active and not deleted. Everything else is staff-only.
- **Localization**: One version per configured language (`ArticleTranslation`, `CategoryTranslation`), one authoritative language per article mirrored onto the main row, fallback or hide for missing languages.
- **Collections**: Curated, ordered, translatable lists of articles (e.g. an FAQ).

### Models & Relationships

- `Article` belongsTo `Category`, hasMany `ArticleVersion`, hasMany `ArticleFeedback`, belongsToMany `Article` (related)
- `Category` belongsTo `Category` (parent), hasMany `Category` (children), hasMany `Article`
- `ArticleVersion` belongsTo `Article`, morphTo `editor`
- `ArticleFeedback` belongsTo `Article`, morphTo `user` (nullable)
- `ArticleRelation` (Pivot) belongsTo `Article` (both sides)
- `Article`, `Category` and `Collection` hasMany their translations (`translations()`, `translation($locale)`, `authoritativeTranslation()`)
- `Collection` belongsToMany `Article` (ordered, via `kb_collection_article`)

All models use `ModelResolver` for class resolution — always use `ModelResolver::article()` instead of hardcoding model classes.

### Configuration

Config file: `config/knowledge-base.php`

Key options:
- `table_prefix` — Database table prefix (default: `kb_`)
- `models.*` — Override default model classes (must implement corresponding contract)
- `default_visibility` — Default article visibility (`public`)
- `versioning_enabled` — Enable article version tracking (default: `true`)
- `feedback_enabled` — Enable feedback system (default: `true`)
- `track_views` — Enable view count tracking (default: `true`)
- `search_engine` — Search engine type (default: `database`)
- `search_results_limit` — Max search results (default: `20`)
- `locales` — Languages served (default: `['en']`)
- `default_locale` — Authoritative language; must be in `locales` (default: `en`)
- `missing_translation` — `fallback` (serve the authoritative version) or `hide`
- `search_languages` — PostgreSQL text-search configuration per language

### Enums

- `ArticleStatus`: `Draft`, `Published`, `Archived`
- `ArticleVisibility`: `Public`, `Internal`
- `TranslationStatus`: `Draft`, `Published`

### Events

- `ArticleCreated` — Dispatched when a new article is created via service
- `ArticlePublished` — Dispatched when an article is published
- `ArticleFeedbackReceived` — Dispatched when feedback is submitted

### Services

`KnowledgeBaseService` provides the main API:

@verbatim
<code-snippet name="Creating an article" lang="php">
$service = app(KnowledgeBaseService::class);

$article = $service->createArticle([
    'category_id' => $category->id,
    'title' => 'Getting Started',
    'slug' => 'getting-started',
    'content' => 'Article content here...',
], $author);
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Publishing and searching" lang="php">
$service->publishArticle($article);
$service->archiveArticle($article);

// Public by default; staff opt in with include_internal or visibility: internal
$results = $service->search('keyword', [
    'category_id' => 1,
    'limit' => 10,
]);

$results = $service->search('Passwort', ['locale' => 'de']); // served_locale / served_translation per result
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Customer-facing queries" lang="php">
$articleClass = ModelResolver::article();

$articles = $articleClass::publiclyVisible()->get();   // never published() on public pages
$staff = $articleClass::staffOnly()->get();
$categories = ModelResolver::category()::publiclyVisible()->get();

$help = app(HelpCenter::class);                          // public-only, language-aware read API
$page = $help->article($slug, 'de');                     // ?ArticlePage (redirectSlug, isFallback, alternates)
$tree = $help->categories('de');
$faq = $help->collection('faq', 'de');
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Feedback" lang="php">
$service->addFeedback($article, true, $user, 'Very helpful!', '127.0.0.1');
$service->addFeedback($article, false); // anonymous not-helpful
</code-snippet>
@endverbatim

### Conventions

- Use `ModelResolver` to reference models (allows custom overrides).
- On customer-facing pages use `publiclyVisible()` or `HelpCenter`, never `published()` (it includes internal content).
- Models implement contracts from `Models\Contracts\` namespace.
- Table names are prefixed via `config('knowledge-base.table_prefix')`.
- Articles generate UUID automatically on creation.
- Version history is created automatically when versioning is enabled.
- The `KnowledgeBaseService` is registered as a singleton.
