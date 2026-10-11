<?php

use JeffersonGoncalves\KnowledgeBase\Models\Category;

beforeEach(function () {
    config([
        'knowledge-base.locales' => ['en', 'de'],
        'knowledge-base.default_locale' => 'en',
        'knowledge-base.missing_translation' => 'fallback',
    ]);
});

describe('Category versions per language', function () {
    it('always has a version in the default language', function () {
        $category = Category::factory()->create(['name' => 'Account', 'slug' => 'account']);

        expect($category->translations)->toHaveCount(1)
            ->and($category->authoritativeTranslation()->locale)->toBe('en')
            ->and($category->authoritativeTranslation()->name)->toBe('Account');
    });

    it('stores a German name next to the English one', function () {
        $category = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);

        $category->translations()->create([
            'locale' => 'de',
            'name' => 'Abrechnung',
            'slug' => 'abrechnung',
            'description' => 'Rechnungen herunterladen.',
        ]);

        expect($category->fresh()->translation('en')->name)->toBe('Billing')
            ->and($category->fresh()->translation('de')->name)->toBe('Abrechnung');
    });

    it('shares the tree, visibility and active flag between versions', function () {
        $parent = Category::factory()->create();
        $category = Category::factory()->withParent($parent)->create(['visibility' => 'internal', 'is_active' => false]);

        $category->translations()->create([
            'locale' => 'de',
            'name' => 'Abrechnung',
            'slug' => 'abrechnung',
        ]);

        $translation = $category->fresh()->translation('de');

        expect($translation->category->parent_id)->toBe($parent->id)
            ->and($translation->category->visibility)->toBe('internal')
            ->and($translation->category->is_active)->toBeFalse();
    });

    it('does not allow a slug that another category already uses', function () {
        Category::factory()->create(['slug' => 'unique-category']);
        $category = Category::factory()->create(['slug' => 'other-category']);

        expect(fn () => $category->translations()->create([
            'locale' => 'de',
            'name' => 'Conflicterend',
            'slug' => 'unique-category',
        ]))->toThrow(InvalidArgumentException::class);
    });
});
