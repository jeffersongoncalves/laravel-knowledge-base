<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        Schema::create($prefix.'article_versions', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('article_id')->constrained($prefix.'articles')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('title');
            $table->longText('content');
            $table->text('excerpt')->nullable();
            $table->morphs('editor');
            $table->text('change_notes')->nullable();
            $table->timestamp('created_at');

            $table->unique(['article_id', 'version_number']);
        });
    }

    public function down(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        Schema::dropIfExists($prefix.'article_versions');
    }
};
