<?php

use JeffersonGoncalves\KnowledgeBase\Support\Locales;

it('returns the configured languages in order', function () {
    config(['knowledge-base.locales' => ['en', 'de']]);

    expect(Locales::all())->toBe(['en', 'de'])
        ->and(Locales::isConfigured('de'))->toBeTrue()
        ->and(Locales::isConfigured('it'))->toBeFalse();
});

it('falls back to a single English language when none is configured', function () {
    config(['knowledge-base.locales' => []]);

    expect(Locales::all())->toBe(['en']);
});

it('returns the configured default language', function () {
    config(['knowledge-base.locales' => ['en', 'de'], 'knowledge-base.default_locale' => 'de']);

    expect(Locales::default())->toBe('de');
});

it('rejects a default language that is not configured', function () {
    config(['knowledge-base.locales' => ['en', 'de'], 'knowledge-base.default_locale' => 'it']);

    expect(fn () => Locales::default())->toThrow(InvalidArgumentException::class);
});

it('returns the configured missing-translation behaviour', function () {
    config(['knowledge-base.missing_translation' => 'hide']);

    expect(Locales::missingTranslation())->toBe(Locales::MISSING_HIDE);
});

it('rejects an unknown missing-translation behaviour', function () {
    config(['knowledge-base.missing_translation' => 'explode']);

    expect(fn () => Locales::missingTranslation())->toThrow(InvalidArgumentException::class);
});

it('maps a locale to a text-search language and defaults to simple', function () {
    config(['knowledge-base.search_languages' => ['en' => 'english', 'de' => 'german']]);

    expect(Locales::searchLanguage('de'))->toBe('german')
        ->and(Locales::searchLanguage('it'))->toBe('simple');
});
