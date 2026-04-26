<?php

namespace Spawn\Symfony\Tests;

use Spawn\Symfony\Translation\AsyncTranslator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

use function Async\delay;

class TranslatorIsolationTest extends AsyncTestCase
{
    private function makeInner(string $defaultLocale): TranslatorInterface&LocaleAwareInterface
    {
        return new class($defaultLocale) implements TranslatorInterface, LocaleAwareInterface {
            public function __construct(private string $locale) {}

            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id . '@' . ($locale ?? $this->locale);
            }

            public function setLocale(string $locale): void { $this->locale = $locale; }
            public function getLocale(): string { return $this->locale; }
        };
    }

    public function test_each_coroutine_gets_its_own_locale(): void
    {
        $translator = new AsyncTranslator($this->makeInner('en'));

        $results = $this->runParallel([
            'en' => function () use ($translator) {
                $translator->setLocale('en');
                delay(200);
                return $translator->getLocale();
            },
            'ru' => function () use ($translator) {
                $translator->setLocale('ru');
                delay(200);
                return $translator->getLocale();
            },
            'de' => function () use ($translator) {
                $translator->setLocale('de');
                delay(200);
                return $translator->getLocale();
            },
        ]);

        $this->assertSame('en', $results['en']);
        $this->assertSame('ru', $results['ru']);
        $this->assertSame('de', $results['de']);
    }

    public function test_trans_uses_coroutine_locale(): void
    {
        $translator = new AsyncTranslator($this->makeInner('en'));

        $results = $this->runParallel([
            'ru' => function () use ($translator) {
                $translator->setLocale('ru');
                delay(100);
                return $translator->trans('hello');
            },
            'de' => function () use ($translator) {
                $translator->setLocale('de');
                delay(100);
                return $translator->trans('hello');
            },
        ]);

        $this->assertSame('hello@ru', $results['ru']);
        $this->assertSame('hello@de', $results['de']);
    }

    public function test_falls_back_to_inner_locale_when_not_set(): void
    {
        $translator = new AsyncTranslator($this->makeInner('fr'));

        $results = $this->runParallel([
            'check' => function () use ($translator) {
                // No setLocale() called — should see inner's default
                return $translator->getLocale();
            },
        ]);

        $this->assertSame('fr', $results['check']);
    }
}
