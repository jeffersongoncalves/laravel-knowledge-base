<div class="filament-hidden">

![Laravel Knowledge Base](https://raw.githubusercontent.com/jeffersongoncalves/laravel-knowledge-base/main/art/jeffersongoncalves-laravel-knowledge-base.png)

</div>

# Laravel Knowledge Base

A Laravel package for building knowledge bases with articles, categories, versioning, feedback, and search.

## Features

- **Article Management** — Create, update, publish, archive, and soft-delete articles with UUID and slug routing
- **Hierarchical Categories** — Nested parent/child categories with ordering and activation control
- **Article Versioning** — Automatic version history tracking with editor attribution and change notes
- **User Feedback** — Helpful/not helpful voting with optional comments, supports authenticated and anonymous users
- **Related Articles** — Many-to-many article relationships with sort ordering
- **Full-Text Search** — Database-powered search across published articles by title and content
- **SEO Fields** — Built-in SEO title, description, and keywords per article
- **Customizable Models** — Override any model via config while maintaining contract compliance
- **Table Prefix** — Configurable table prefix to avoid naming collisions (default: `kb_`)
- **Translations** — English and Brazilian Portuguese out of the box

## Requirements

- PHP 8.2+
- Laravel 11+

## Installation

```bash
composer require jeffersongoncalves/laravel-knowledge-base
```

Publish and run migrations:

```bash
php artisan vendor:publish --tag="knowledge-base-migrations"
php artisan migrate
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag="knowledge-base-config"
```

## Configuration

The config file (`config/knowledge-base.php`) covers:

### Table Prefix

```php
'table_prefix' => 'kb_',
```

Set to `null` to use table names without a prefix.

### Custom Models

Override any model to extend the default behavior. Custom models must implement the corresponding contract:

```php
'models' => [
    'article' => \App\Models\Article::class,
    'category' => \App\Models\Category::class,
    'article_version' => \App\Models\ArticleVersion::class,
    'article_feedback' => \App\Models\ArticleFeedback::class,
    'article_relation' => \App\Models\ArticleRelation::class,
],
```

### Knowledge Base Settings

```php
'default_visibility' => 'public',   // default visibility for new articles
'versioning_enabled' => true,       // track article version history
'feedback_enabled' => true,         // allow user feedback
'track_views' => true,              // track article view counts
```

### Search

```php
'search_engine' => 'database',      // search engine type
'search_results_limit' => 20,       // max results per search
```

## Usage

### Using the Service

The `KnowledgeBaseService` is the recommended way to interact with the knowledge base:

```php
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;

$service = app(KnowledgeBaseService::class);
```

### Creating Categories

```php
// Auto-generates slug from name
$category = $service->createCategory([
    'name' => 'Getting Started',
]);

// With custom slug and parent
$child = $service->createCategory([
    'name' => 'Installation',
    'slug' => 'installation-guide',
    'parent_id' => $category->id,
    'description' => 'How to install the application',
    'icon' => 'heroicon-o-wrench',
    'sort_order' => 1,
]);

// Update
$service->updateCategory($category, ['name' => 'Quick Start']);

// Delete (soft delete)
$service->deleteCategory($category);
```

### Creating Articles

```php
$article = $service->createArticle([
    'category_id' => $category->id,
    'title' => 'How to Install',
    'slug' => 'how-to-install',
    'content' => 'Step 1: Run composer require...',
    'excerpt' => 'Quick installation guide.',
    'visibility' => 'public',
    'seo_title' => 'Installation Guide - My App',
    'seo_description' => 'Learn how to install My App step by step.',
    'seo_keywords' => 'install, setup, getting started',
    'metadata' => ['difficulty' => 'beginner'],
], $author); // $author is any Eloquent Model (User, Admin, etc.)
```

This automatically:
- Generates a UUID
- Creates version 1 (when versioning enabled)
- Dispatches `ArticleCreated` event

### Updating Articles

```php
$updated = $service->updateArticle($article, [
    'title' => 'How to Install (Updated)',
    'content' => 'Updated installation steps...',
], $editor, 'Fixed outdated instructions');
```

When versioning is enabled, this creates a new version entry with the editor and change notes.

### Publishing & Archiving

```php
// Publish — sets status to Published, sets published_at, dispatches ArticlePublished
$service->publishArticle($article);

// Archive — sets status to Archived
$service->archiveArticle($article);

// Delete — soft deletes
$service->deleteArticle($article);
```

### Feedback

```php
// Authenticated helpful feedback
$service->addFeedback($article, true, $user, 'Very clear, thank you!', '127.0.0.1');

// Anonymous not-helpful feedback
$service->addFeedback($article, false, null, 'Needs more examples');

// Anonymous without comment
$service->addFeedback($article, true);
```

Feedback automatically increments `helpful_count` or `not_helpful_count` on the article and dispatches `ArticleFeedbackReceived`.

### Search

```php
// Basic search (only published articles)
$results = $service->search('installation');

// With filters
$results = $service->search('installation', [
    'category_id' => 1,
    'visibility' => 'public',
    'limit' => 10,
]);
```

Results are ordered by `view_count` descending (most popular first).

### Using Models Directly

For queries outside the service, use `ModelResolver`:

```php
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

$articleClass = ModelResolver::article();
$categoryClass = ModelResolver::category();

// Article scopes
$published = $articleClass::published()->get();
$drafts = $articleClass::draft()->get();
$archived = $articleClass::archived()->get();
$internal = $articleClass::byVisibility(ArticleVisibility::Internal)->get();

// Category scopes
$active = $categoryClass::active()->get();
$roots = $categoryClass::root()->get();
$ordered = $categoryClass::ordered()->get();
$tree = $categoryClass::active()->root()->ordered()->get();

// Relationships
$article->category;
$article->author;
$article->versions;
$article->feedback;
$article->relatedArticles;

$category->parent;
$category->children;
$category->articles;

// View tracking
$article->incrementViewCount();
```

### Extending Models

Create custom models that implement the required contract:

```php
namespace App\Models;

use JeffersonGoncalves\KnowledgeBase\Models\Article as BaseArticle;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;

class Article extends BaseArticle implements ArticleContract
{
    // Add custom relationships, scopes, methods...

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
}
```

Then update the config:

```php
// config/knowledge-base.php
'models' => [
    'article' => \App\Models\Article::class,
    // ...
],
```

## Events

| Event | Payload | When |
|-------|---------|------|
| `ArticleCreated` | `$article` | After article creation via service |
| `ArticlePublished` | `$article` | After publishing via service |
| `ArticleFeedbackReceived` | `$article`, `$feedback` | After feedback submission |

Listen to events in your `EventServiceProvider` or with listeners:

```php
use JeffersonGoncalves\KnowledgeBase\Events\ArticlePublished;

class SendArticleNotification
{
    public function handle(ArticlePublished $event): void
    {
        // Notify subscribers about the new article
        $article = $event->article;
    }
}
```

## Enums

### ArticleStatus

| Value | Label (en) | Label (pt_BR) |
|-------|-----------|---------------|
| `draft` | Draft | Rascunho |
| `published` | Published | Publicado |
| `archived` | Archived | Arquivado |

### ArticleVisibility

| Value | Label (en) | Label (pt_BR) |
|-------|-----------|---------------|
| `public` | Public | Público |
| `internal` | Internal | Interno |

```php
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;

$status = ArticleStatus::Published;
$status->label(); // 'Published' or 'Publicado'

$visibility = ArticleVisibility::Internal;
$visibility->label(); // 'Internal' or 'Interno'
```

## Database Tables

All tables use the configured prefix (default: `kb_`):

| Table | Description |
|-------|-------------|
| `kb_categories` | Hierarchical article categories |
| `kb_articles` | Knowledge base articles |
| `kb_article_versions` | Article version history |
| `kb_article_feedback` | User feedback on articles |
| `kb_article_relations` | Related articles (pivot) |

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
