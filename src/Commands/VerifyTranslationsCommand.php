<?php

namespace JeffersonGoncalves\KnowledgeBase\Commands;

use Illuminate\Console\Command;
use JeffersonGoncalves\KnowledgeBase\Support\TranslationVerifier;

class VerifyTranslationsCommand extends Command
{
    protected $signature = 'kb:verify-translations';

    protected $description = 'Report drift between main rows and their authoritative translations';

    public function handle(TranslationVerifier $verifier): int
    {
        $drift = $verifier->drift();

        if ($drift === []) {
            $this->info('All translations are in sync.');

            return self::SUCCESS;
        }

        foreach ($drift as $line) {
            $this->error($line);
        }

        $this->warn(count($drift).' drift(s) found.');

        return self::FAILURE;
    }
}
