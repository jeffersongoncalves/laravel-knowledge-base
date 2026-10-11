<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use InvalidArgumentException;

/**
 * The single definition of the configured languages (see design D7).
 *
 * Everything that needs to know which languages the knowledge base serves
 * reads it through here, so config validation and defaults live in one place,
 * like {@see Visibility} does for the public/staff rule.
 */
class Locales
{
    /** The only accepted values of `knowledge-base.missing_translation`. */
    public const MISSING_FALLBACK = 'fallback';

    public const MISSING_HIDE = 'hide';

    /**
     * The configured languages, in configuration order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $locales = config('knowledge-base.locales', ['en']);

        if (! is_array($locales) || $locales === []) {
            return ['en'];
        }

        return array_values(array_map('strval', $locales));
    }

    /**
     * The configured default (authoritative) language.
     *
     * @throws InvalidArgumentException when it is not one of the configured languages
     */
    public static function default(): string
    {
        $default = config('knowledge-base.default_locale', 'en');
        $default = is_string($default) ? $default : '';

        if (! static::isConfigured($default)) {
            throw new InvalidArgumentException(
                "Default locale [{$default}] is not part of the configured locales [".implode(', ', static::all()).'].'
            );
        }

        return $default;
    }

    public static function isConfigured(string $locale): bool
    {
        return in_array($locale, static::all(), true);
    }

    /**
     * What a reader gets when the requested language has no readable version.
     *
     * @throws InvalidArgumentException when the configured value is not recognised
     */
    public static function missingTranslation(): string
    {
        $mode = config('knowledge-base.missing_translation', static::MISSING_FALLBACK);

        if (! in_array($mode, [static::MISSING_FALLBACK, static::MISSING_HIDE], true)) {
            throw new InvalidArgumentException(
                "Unknown missing_translation [{$mode}]; expected 'fallback' or 'hide'."
            );
        }

        return $mode;
    }

    /**
     * The PostgreSQL text-search configuration for a locale (see design D4).
     */
    public static function searchLanguage(string $locale): string
    {
        /** @var array<string, mixed> $languages */
        $languages = config('knowledge-base.search_languages', []);

        $language = $languages[$locale] ?? null;

        return is_string($language) && $language !== '' ? $language : 'simple';
    }
}
