<?php

namespace JeffersonGoncalves\KnowledgeBase\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;
use JeffersonGoncalves\KnowledgeBase\Models\Article;

/** @extends Factory<Article> */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->sentence();

        return [
            'uuid' => (string) Str::uuid(),
            'title' => $title,
            'slug' => Str::slug($title),
            'content' => fake()->paragraphs(3, true),
            'excerpt' => fake()->sentence(),
            'status' => ArticleStatus::Draft,
            'visibility' => ArticleVisibility::Public,
            'view_count' => 0,
            'helpful_count' => 0,
            'not_helpful_count' => 0,
            'current_version' => 1,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ArticleStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ArticleStatus::Archived,
        ]);
    }

    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => ArticleVisibility::Internal,
        ]);
    }
}
