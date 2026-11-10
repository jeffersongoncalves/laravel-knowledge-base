<?php

use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;

it('has correct values', function () {
    expect(ArticleVisibility::Public->value)->toBe('public');
    expect(ArticleVisibility::Customers->value)->toBe('customers');
    expect(ArticleVisibility::Internal->value)->toBe('internal');
});

it('returns translated label', function () {
    expect(ArticleVisibility::Public->label())->toBeString();
    expect(ArticleVisibility::Customers->label())->toBe('Signed-in customers');
    expect(ArticleVisibility::Internal->label())->toBeString();
});
