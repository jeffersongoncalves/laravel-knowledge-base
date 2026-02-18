## Laravel Knowledge Base

This package provides a complete knowledge base system for Laravel applications with articles, categories, versioning, feedback, and search capabilities.

### Key Concepts

- **Articles**: Content pieces with title, slug, content, SEO fields, and versioning support.
- **Categories**: Hierarchical structure (parent/child) for organizing articles.
- **Versions**: Article version history with editor tracking and change notes.
- **Feedback**: User feedback (helpful/not helpful) with optional comments and IP tracking.
- **Related Articles**: Many-to-many self-referencing relationships between articles.

### Models & Relationships

- `Article` belongsTo `Category`, hasMany `ArticleVersion`, hasMany `ArticleFeedback`, belongsToMany `Article` (related)
- `Category` belongsTo `Category` (parent), hasMany `Category` (children), hasMany `Article`
- `ArticleVersion` belongsTo `Article`, morphTo `editor`
- `ArticleFeedback` belongsTo `Article`, morphTo `user` (nullable)
- `ArticleRelation` (Pivot) belongsTo `Article` (both sides)

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

### Enums

- `ArticleStatus`: `Draft`, `Published`, `Archived`
- `ArticleVisibility`: `Public`, `Internal`

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

$results = $service->search('keyword', [
    'category_id' => 1,
    'visibility' => 'public',
    'limit' => 10,
]);
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
- Models implement contracts from `Models\Contracts\` namespace.
- Table names are prefixed via `config('knowledge-base.table_prefix')`.
- Articles generate UUID automatically on creation.
- Version history is created automatically when versioning is enabled.
- The `KnowledgeBaseService` is registered as a singleton.
