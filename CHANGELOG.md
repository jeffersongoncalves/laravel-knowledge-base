# Changelog

All notable changes to this project will be documented in this file.

## v2.0.0 - 2026-10-11

### Breaking changes

- **The authoritative language is always the configured default language**, for articles as it already was for categories and collections. `Article::changeSourceLocale()` (and its entry in `TranslatableArticleContract`) and the `kb_articles.source_locale` column are removed. The `remove_kb_article_source_locale` migration re-mirrors every article whose authoritative language was not the default from its default-language version, then drops the column; it stops before changing anything if an article has no default-language version. Code that called `changeSourceLocale()` or read `source_locale` uses `authoritativeLocale()` instead.

### Features

- **Article assets**: images and files belong to an article, stored in one folder per article (`{directory}/articles/{uuid}/shared|{locale}/`), shared across languages or per language. Content refers to them by id; `ArticleAssets::resolve()` builds current URLs at render time, so moving the site to another domain no longer breaks images. New table `kb_article_assets`, model `ArticleAsset`, enum `AssetKind`, and `attachments` config (disk, directory, accepted types and sizes per kind).
- `php artisan kb:prune-assets` (`--dry-run`) removes stray files and folders of deleted articles; files written in the past hour are kept.

### Fixes

- `ArticleAssets::store()` throws when the disk reports a failed write, so no record points at a missing file.
- Rolling back `remove_kb_article_source_locale` no longer depends on a valid language configuration.

### Upgrading

1. `php artisan vendor:publish --tag=knowledge-base-migrations` and `php artisan migrate`.
2. With the default `public` disk, run `php artisan storage:link` once.
3. Apps rendering article content themselves pass it through `ArticleAssets::resolve($html, $article, forPublic: true)`.

Thanks @rcoenen for the contribution (#20).

## v1.1.0 - 2026-10-11

### What's new

- **Public visibility** (#15): `Article::publiclyVisible()`, `Article::staffOnly()` and `Category::publiclyVisible()`. An article is public only when it is published, `public`, and its category and every ancestor are `public`, active and not deleted. Default `search()` now uses this rule, closing a leak of public articles in internal, inactive or deleted categories.
- **Content localization** (#16): one version per configured language for articles and categories, an authoritative language mirrored onto the main row, `fallback`/`hide` for missing languages, per-language slugs, outdated flags, per-language history, language-aware search (PostgreSQL stemming per language) and `php artisan kb:verify-translations`.
- **Collections and Help Center API** (#17): curated, ordered, translatable lists of articles (e.g. an FAQ) and `Support\HelpCenter`, a public-only, language-aware read API for help pages.
- **Translations** (#18): enum labels in 19 languages (ar, az, de, en, es, fa, fr, hi, it, ja, nl, pl, pt, pt_BR, ru, tr, uk, uz, zh_CN); README and Laravel Boost guidelines updated.

### Upgrading

Two additive migrations: `add_kb_content_localization` and `add_kb_collections`. Re-publish the migrations, and **set `knowledge-base.default_locale` to the language your existing content is written in before running `migrate`**: the upgrade tags all existing content with it. No breaking API changes; `published()` is unchanged but is not customer-safe, so use `publiclyVisible()` on public pages.

Thanks @rcoenen for #15, #16 and #17.

## v1.0.3 - 2026-04-26

### What's Changed

* build(deps): Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/jeffersongoncalves/laravel-knowledge-base/pull/1
* build(deps): Bump dependabot/fetch-metadata from 2.5.0 to 3.0.0 by @dependabot[bot] in https://github.com/jeffersongoncalves/laravel-knowledge-base/pull/2

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/jeffersongoncalves/laravel-knowledge-base/pull/1

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-knowledge-base/compare/v1.0.2...v1.0.3

## v1.0.2 - 2026-02-24

### What's Changed

- Add Laravel 13.x support (illuminate/* ^13.0) in composer.json and CI test matrix
- Add orchestra/testbench ^11.0 for Laravel 13 testing

## v1.0.1 - 2026-02-17

### What's Changed

#### Bug Fixes

- Resolve all Larastan level 5 errors by replacing intersection types with concrete model classes
  - Replace `Model&Contract` intersection types in `@property-read` with concrete model classes
  - Remove unsupported `@return` generic annotations from relationship methods
  - Use `@var Article` annotations in `KnowledgeBaseService` for proper property resolution
  - Fix `createCategory` return type mismatch
  - Remove invalid `checkMissingIterableValueType` from `phpstan.neon.dist`
  

**Full Changelog**: https://github.com/jeffersongoncalves/laravel-knowledge-base/compare/v1.0.0...v1.0.1

## v1.0.0 - 2026-02-17

### What's New

#### Initial Release

- **Article Management** — Create, update, publish, archive, and soft-delete articles with UUID and slug routing
- **Hierarchical Categories** — Nested parent/child categories with ordering and activation control
- **Article Versioning** — Automatic version history tracking with editor attribution and change notes
- **User Feedback** — Helpful/not helpful voting with optional comments, supports authenticated and anonymous users
- **Related Articles** — Many-to-many article relationships with sort ordering
- **Full-Text Search** — Database-powered search across published articles by title and content
- **SEO Fields** — Built-in SEO title, description, and keywords per article
- **Customizable Models** — Override any model via config while maintaining contract compliance (ModelResolver pattern)
- **Table Prefix** — Configurable table prefix to avoid naming collisions (default: `kb_`)
- **Contracts** — ArticleContract, CategoryContract, ArticleVersionContract, ArticleFeedbackContract, ArticleRelationContract
- **Events** — ArticleCreated, ArticlePublished, ArticleFeedbackReceived
- **Translations** — English and Brazilian Portuguese
- **Laravel Boost** — Guidelines and skill documentation included
- **48 Pest tests** — Full coverage for models, enums, service, and ModelResolver
