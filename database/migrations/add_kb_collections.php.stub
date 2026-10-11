<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds curated collections of articles (see design D1).
 *
 * Creates `kb_collections` (the default-language version mirrored on the main
 * row, like categories), `kb_collection_translations` (one version per
 * configured language, a slug belonging to one collection in any language) and
 * `kb_collection_article` (the ordered membership, one article at most once per
 * collection). Purely additive; rollback drops the three tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        $this->createCollectionsTable($prefix);
        $this->createCollectionTranslationsTable($prefix);
        $this->createCollectionArticleTable($prefix);
    }

    public function down(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        Schema::dropIfExists($prefix.'collection_article');
        Schema::dropIfExists($prefix.'collection_translations');
        Schema::dropIfExists($prefix.'collections');
    }

    protected function createCollectionsTable(string $prefix): void
    {
        if (Schema::hasTable($prefix.'collections')) {
            return;
        }

        Schema::create($prefix.'collections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function createCollectionTranslationsTable(string $prefix): void
    {
        if (Schema::hasTable($prefix.'collection_translations')) {
            return;
        }

        Schema::create($prefix.'collection_translations', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('collection_id')->constrained($prefix.'collections')->cascadeOnDelete();
            $table->string('locale', 16);
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['collection_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });
    }

    protected function createCollectionArticleTable(string $prefix): void
    {
        if (Schema::hasTable($prefix.'collection_article')) {
            return;
        }

        Schema::create($prefix.'collection_article', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('collection_id')->constrained($prefix.'collections')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained($prefix.'articles')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['collection_id', 'article_id']);
        });
    }
};
