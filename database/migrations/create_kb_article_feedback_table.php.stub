<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        Schema::create($prefix.'article_feedback', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('article_id')->constrained($prefix.'articles')->cascadeOnDelete();
            $table->nullableMorphs('user');
            $table->boolean('is_helpful');
            $table->text('comment')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        Schema::dropIfExists($prefix.'article_feedback');
    }
};
