---
name: knowledge-base-development
description: Build and work with Laravel Knowledge Base features, including articles, categories, versioning, feedback, search, and customizable models.
---

# Knowledge Base Development

## When to use this skill

Use this skill when:
- Creating or managing knowledge base articles and categories
- Working with article versioning and change tracking
- Implementing article feedback (helpful/not helpful)
- Searching published articles
- Extending or customizing models with contracts
- Configuring table prefix or custom model classes

## Database Schema

### Tables (prefix: `kb_`)

| Table | Purpose |
|-------|---------|
| `kb_categories` | Hierarchical article categories |
| `kb_articles` | Knowledge base articles with SEO and versioning |
| `kb_article_versions` | Article version history |
| `kb_article_feedback` | User feedback on articles |
| `kb_article_relations` | Related articles pivot |

## Model Usage

Always use `ModelResolver` to reference models — never hardcode class names:

```php
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

// Correct
$article = ModelResolver::article()::create([...]);
$category = ModelResolver::category()::find($id);

// Incorrect - don't do this
$article = new Article();
```

### Available Resolvers

```php
ModelResolver::article();          // ArticleContract
ModelResolver::category();         // CategoryContract
ModelResolver::articleVersion();   // ArticleVersionContract
ModelResolver::articleFeedback();  // ArticleFeedbackContract
ModelResolver::articleRelation();  // ArticleRelationContract
```

## KnowledgeBaseService

The main service provides all CRUD operations:

### Creating Articles

```php
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;

$service = app(KnowledgeBaseService::class);

$article = $service->createArticle([
    'category_id' => $category->id,
    'title' => 'Getting Started Guide',
    'slug' => 'getting-started-guide',
    'content' => 'Full article content here...',
    'excerpt' => 'A brief summary.',
    'visibility' => 'public',
], $author); // $author is any Eloquent Model
```

### Updating with Versioning

```php
$updated = $service->updateArticle($article, [
    'title' => 'Updated Title',
    'content' => 'Updated content...',
], $editor, 'Fixed typos and added examples');
// Automatically creates version 2 when versioning_enabled = true
```

### Publishing & Archiving

```php
$service->publishArticle($article);  // Sets status=Published, dispatches ArticlePublished
$service->archiveArticle($article);  // Sets status=Archived
$service->deleteArticle($article);   // Soft deletes
```

### Categories

```php
$category = $service->createCategory([
    'name' => 'FAQ',
    // slug auto-generated from name if not provided
]);

$service->updateCategory($category, ['name' => 'Frequently Asked Questions']);
$service->deleteCategory($category); // Soft deletes
```

### Feedback

```php
// Authenticated feedback
$service->addFeedback($article, true, $user, 'Very helpful!', '127.0.0.1');

// Anonymous feedback
$service->addFeedback($article, false, null, 'Needs improvement');

// Automatically updates helpful_count / not_helpful_count
```

### Search

```php
$results = $service->search('Laravel installation', [
    'category_id' => 1,           // optional
    'visibility' => 'public',     // optional
    'limit' => 10,                // optional (default from config)
]);
// Only searches published articles, ordered by view_count desc
```

## Article Scopes

```php
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;

$articleClass = ModelResolver::article();

$articleClass::published()->get();
$articleClass::draft()->get();
$articleClass::archived()->get();
$articleClass::byVisibility(ArticleVisibility::Internal)->get();
```

## Category Scopes

```php
$categoryClass = ModelResolver::category();

$categoryClass::active()->get();         // is_active = true
$categoryClass::root()->get();           // parent_id is null
$categoryClass::ordered()->get();        // ordered by sort_order
$categoryClass::active()->root()->ordered()->get(); // combined
```

## Enums

```php
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;

ArticleStatus::Draft;       // 'draft'
ArticleStatus::Published;   // 'published'
ArticleStatus::Archived;    // 'archived'

ArticleVisibility::Public;   // 'public'
ArticleVisibility::Internal; // 'internal'

// Both have ->label() for translated labels
ArticleStatus::Draft->label(); // 'Rascunho' (pt_BR) or 'Draft' (en)
```

## Events

| Event | Payload | When |
|-------|---------|------|
| `ArticleCreated` | `$article` | After article creation via service |
| `ArticlePublished` | `$article` | After publishing via service |
| `ArticleFeedbackReceived` | `$article`, `$feedback` | After feedback submission |

## Contracts

All models implement corresponding contracts:

| Contract | Model | Key Methods |
|----------|-------|-------------|
| `ArticleContract` | `Article` | `category()`, `author()`, `versions()`, `feedback()`, `relatedArticles()`, `incrementViewCount()` |
| `CategoryContract` | `Category` | `parent()`, `children()`, `articles()` |
| `ArticleVersionContract` | `ArticleVersion` | `article()`, `editor()` |
| `ArticleFeedbackContract` | `ArticleFeedback` | `article()`, `user()` |
| `ArticleRelationContract` | `ArticleRelation` | `article()`, `relatedArticle()` |

## Extending Models

Override models in config — custom models must implement the corresponding contract:

```php
// config/knowledge-base.php
'models' => [
    'article' => \App\Models\CustomArticle::class,
    'category' => \App\Models\CustomCategory::class,
    // ...
],
```

```php
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;

class CustomArticle extends Article implements ArticleContract
{
    // Add custom methods, scopes, relationships...
}
```

## Configuration Reference

```php
// config/knowledge-base.php
return [
    'table_prefix' => 'kb_',              // null for no prefix
    'models' => [
        'article' => Article::class,
        'category' => Category::class,
        'article_version' => ArticleVersion::class,
        'article_feedback' => ArticleFeedback::class,
        'article_relation' => ArticleRelation::class,
    ],
    'default_visibility' => 'public',
    'versioning_enabled' => true,
    'feedback_enabled' => true,
    'track_views' => true,
    'search_engine' => 'database',
    'search_results_limit' => 20,
];
```

## Testing

The package uses Pest with Orchestra Testbench and SQLite in-memory database:

```php
use JeffersonGoncalves\KnowledgeBase\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
```

Run tests:

```bash
php vendor/bin/pest
```
