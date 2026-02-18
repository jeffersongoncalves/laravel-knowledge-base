<?php

use JeffersonGoncalves\KnowledgeBase\Models\Category;

beforeEach(function () {
    \JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User::createTable();
});

it('can create a category', function () {
    $category = Category::factory()->create([
        'name' => 'Getting Started',
        'slug' => 'getting-started',
    ]);

    expect($category)
        ->name->toBe('Getting Started')
        ->slug->toBe('getting-started')
        ->is_active->toBeTrue();
});

it('uses table prefix from config', function () {
    $category = new Category;

    expect($category->getTable())->toBe('kb_categories');
});

it('respects custom table prefix', function () {
    config(['knowledge-base.table_prefix' => 'custom_']);

    $category = new Category;

    expect($category->getTable())->toBe('custom_categories');

    config(['knowledge-base.table_prefix' => 'kb_']);
});

it('can have parent-child relationship', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $parent->id]);

    expect($child->parent->id)->toBe($parent->id);
    expect($parent->children)->toHaveCount(1);
    expect($parent->children->first()->id)->toBe($child->id);
});

it('scopes active categories', function () {
    Category::factory()->create(['is_active' => true]);
    Category::factory()->create(['is_active' => false]);

    expect(Category::active()->count())->toBe(1);
});

it('scopes root categories', function () {
    $parent = Category::factory()->create();
    Category::factory()->create(['parent_id' => $parent->id]);

    expect(Category::root()->count())->toBe(1);
});

it('scopes ordered categories', function () {
    Category::factory()->create(['sort_order' => 2, 'name' => 'Second']);
    Category::factory()->create(['sort_order' => 1, 'name' => 'First']);

    $ordered = Category::ordered()->get();

    expect($ordered->first()->name)->toBe('First');
    expect($ordered->last()->name)->toBe('Second');
});

it('uses slug as route key', function () {
    $category = new Category;

    expect($category->getRouteKeyName())->toBe('slug');
});

it('soft deletes categories', function () {
    $category = Category::factory()->create();
    $category->delete();

    expect(Category::count())->toBe(0);
    expect(Category::withTrashed()->count())->toBe(1);
});
