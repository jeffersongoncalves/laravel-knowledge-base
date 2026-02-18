# Changelog

All notable changes to this project will be documented in this file.

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
