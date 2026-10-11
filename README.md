<div class="filament-hidden">

![Laravel Knowledge Base](https://raw.githubusercontent.com/jeffersongoncalves/laravel-knowledge-base/main/art/jeffersongoncalves-laravel-knowledge-base.png)

</div>

# Laravel Knowledge Base

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jeffersongoncalves/laravel-knowledge-base.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-knowledge-base)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-knowledge-base/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jeffersongoncalves/laravel-knowledge-base/actions?query=workflow%3Atests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-knowledge-base/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/jeffersongoncalves/laravel-knowledge-base/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-knowledge-base/phpstan.yml?branch=main&label=PHPStan&style=flat-square)](https://github.com/jeffersongoncalves/laravel-knowledge-base/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/jeffersongoncalves/laravel-knowledge-base.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-knowledge-base)
[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-FFDD00?style=flat-square&logo=buy-me-a-coffee&logoColor=black)](https://buymeacoffee.com/jeffersongoncalves)


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

> **Upgrading an existing knowledge base:** before running `migrate`, set
> `knowledge-base.default_locale` to the language your existing content is
> written in (default `en`). The localization migration tags every existing
> article and category with that language; a wrong value mislabels all content.

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
    'article_translation' => \App\Models\ArticleTranslation::class,
    'category_translation' => \App\Models\CategoryTranslation::class,
],
```

### Knowledge Base Settings

```php
'default_visibility' => 'public',   // default visibility for new articles
'versioning_enabled' => true,       // track article version history
'feedback_enabled' => true,         // allow user feedback
'track_views' => true,              // track article view counts
```

### Languages

```php
'locales' => ['en'],                // languages the knowledge base serves
'default_locale' => 'en',           // authoritative language; must be in locales
'missing_translation' => 'fallback', // 'fallback' or 'hide'
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
// Basic search (only articles customers may see, see "Public vs Internal Content")
$results = $service->search('installation');

// With filters
$results = $service->search('installation', [
    'category_id' => 1,
    'visibility' => 'public',
    'limit' => 10,
]);

// Staff search
$all = $service->search('installation', ['include_internal' => true]); // every published article
$staffOnly = $service->search('installation', ['visibility' => 'internal']); // only staff-only articles
```

Results are ordered by `view_count` descending (most popular first).

### Using Models Directly

For queries outside the service, use `ModelResolver`:

```php
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

$articleClass = ModelResolver::article();
$categoryClass = ModelResolver::category();

// Article scopes
$published = $articleClass::published()->get(); // staff only, see below
$public = $articleClass::publiclyVisible()->get();
$staffOnly = $articleClass::staffOnly()->get();
$drafts = $articleClass::draft()->get();
$archived = $articleClass::archived()->get();
$internal = $articleClass::byVisibility(ArticleVisibility::Internal)->get();

// Category scopes
$public = $categoryClass::publiclyVisible()->get();
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

### Public vs Internal Content

Both articles and categories have a `visibility` of `public` or `internal`. An article may be shown to customers only when **all** of these hold:

- the article is published,
- the article's visibility is `public`,
- its category, and every parent category above it, is `public`, active and not deleted.

Every other published article is staff-only. A public article inside an internal category is staff-only too, and a category whose parent is missing counts as hidden.

| Query | Returns | Use for |
|-------|---------|---------|
| `publiclyVisible()` | Articles that pass the rule above | Help pages, FAQ, anything a customer can see |
| `staffOnly()` | Published articles that fail it | Staff-only lists |
| `published()` | Every published article, whatever its visibility | Admin and staff tools only |

`publiclyVisible()` and `staffOnly()` split the published articles: each one is returned by exactly one of them. `Category::publiclyVisible()` applies the same category rule to category menus. All of them combine with other constraints:

```php
$article = $articleClass::publiclyVisible()->where('slug', $slug)->firstOrFail();
$articles = $category->articles()->publiclyVisible()->get();
$related = $article->relatedArticles()->publiclyVisible()->get();
```

Content meant for customers and staff alike is simply `public`: staff tools use `published()` and see it as well.

> **Note:** `published()` filters on status only. Do not use it on customer-facing pages, or internal content will show up there.

### Localization

The knowledge base can serve its articles and categories in several languages. Configure them once:

```php
// config/knowledge-base.php
'locales' => ['en', 'de'],        // languages the knowledge base serves
'default_locale' => 'en',         // authoritative language for new content; must be in locales
'missing_translation' => 'fallback', // 'fallback' (default) or 'hide'
'search_languages' => [           // PostgreSQL text-search configuration per language
    'en' => 'english',
    'de' => 'german',
],
```

With a single configured language the knowledge base behaves exactly as before this change.

**One version per language.** Each article and category has at most one version per configured language, with its own title, slug, excerpt, content and SEO fields (articles) or name, slug and description (categories). Category, visibility, related articles, author and counters are shared by every version.

```php
$german = $article->translations()->create([
    'locale' => 'de',
    'title' => 'Passwort zurücksetzen',
    'slug' => 'passwort-zuruecksetzen',
    'content' => 'So setzt du dein Passwort zurück.',
]);
```

**The authoritative language.** Every article has exactly one authoritative language, defaulting to `default_locale`, and always has a version in it. Its version is mirrored onto the article's own `title`, `slug`, `content`, `excerpt` and SEO columns, in both directions: writing those columns updates the authoritative version, and editing the authoritative version updates those columns. This is what keeps existing code that reads `$article->title` working.

```php
$article->source_locale;                      // 'en'
$article->authoritativeTranslation()->title;  // the mirrored title
$article->changeSourceLocale('de');           // only to a language it has a version in
```

Categories always have a version in `default_locale`; their main row mirrors it.

**Slugs.** A slug belongs to one article or category: the same article may use the same slug in several of its languages, but two different articles may never share a slug in any language. Saving a conflicting slug or a second version in the same language is rejected.

**Outdated versions.** Saving the authoritative version can mark every other version as outdated; readers still see outdated versions.

```php
$article->flagOtherTranslationsOutdated(); // mark the other languages
$translation->markUpToDate();              // editing or confirming a version clears it
```

Run `php artisan kb:verify-translations` to report any drift between a main row and its authoritative version.

> **Before migrating an existing knowledge base**, set `default_locale` to the language the existing content is written in. The upgrade tags every existing article and category with that language, so a wrong value mislabels all existing content. See the migration note below.

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
| `kb_category_translations` | Category name/slug/description per language |
| `kb_articles` | Knowledge base articles |
| `kb_article_translations` | Article title/slug/content per language |
| `kb_article_versions` | Article version history, per language |
| `kb_article_feedback` | User feedback on articles |
| `kb_article_relations` | Related articles (pivot) |

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jefferson Gonçalves](https://github.com/jeffersongoncalves)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
