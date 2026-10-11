<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds article assets (see design D2): the images and files an article owns.
 *
 * One row per stored file, carrying its kind (image or file), its scope (null
 * for shared, or a configured non-default language), the disk and path it lives
 * on, its original name, detected type, size and, for images PHP can read, its
 * width and height. The article's uuid names the folder the file lives in (see
 * design D3), so `kb_article_assets.uuid` is a separate identifier that content
 * refers to. Purely additive; rollback drops the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        if (Schema::hasTable($prefix.'article_assets')) {
            return;
        }

        Schema::create($prefix.'article_assets', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('article_id')->constrained($prefix.'articles')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('locale', 16)->nullable();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();

            $table->index(['article_id', 'locale']);
        });
    }

    public function down(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        Schema::dropIfExists($prefix.'article_assets');
    }
};
