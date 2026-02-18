<?php

use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;

it('has correct values', function () {
    expect(ArticleStatus::Draft->value)->toBe('draft');
    expect(ArticleStatus::Published->value)->toBe('published');
    expect(ArticleStatus::Archived->value)->toBe('archived');
});

it('returns translated label', function () {
    expect(ArticleStatus::Draft->label())->toBeString();
    expect(ArticleStatus::Published->label())->toBeString();
    expect(ArticleStatus::Archived->label())->toBeString();
});
