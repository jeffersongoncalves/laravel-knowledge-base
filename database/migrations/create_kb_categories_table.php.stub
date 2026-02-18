<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        Schema::create($prefix.'categories', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained($prefix.'categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('visibility', 16)->default('public');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        Schema::dropIfExists($prefix.'categories');
    }
};
