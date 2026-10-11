<?php

use Illuminate\Support\Arr;

it('has the same keys in every language file as in English', function (string $locale) {
    $flatten = fn (array $lines) => array_keys(Arr::dot($lines));

    $english = $flatten(require __DIR__.'/../../resources/lang/en/knowledge-base.php');
    $other = $flatten(require __DIR__."/../../resources/lang/{$locale}/knowledge-base.php");

    expect($other)->toEqualCanonicalizing($english);
})->with(fn () => array_map('basename', glob(__DIR__.'/../../resources/lang/*', GLOB_ONLYDIR)));
