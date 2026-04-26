<?php

namespace Spawn\Symfony\Translation;

use Spawn\Symfony\ScopedService;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Async\current_context;

/**
 * Per-coroutine Translator decorator.
 *
 * Translator is @final since Symfony 7.1 so we cannot extend it.
 * Instead we decorate it: all calls are delegated to the inner translator,
 * but setLocale/getLocale redirect to current_context() so each coroutine
 * (request) has its own locale without interfering with others.
 *
 * trans() falls back to current coroutine's locale when no explicit locale
 * is passed, ensuring translations use the correct per-request locale.
 */
class AsyncTranslator implements TranslatorInterface, LocaleAwareInterface
{
    public function __construct(
        private readonly TranslatorInterface&LocaleAwareInterface $inner,
    ) {}

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->inner->trans($id, $parameters, $domain, $locale ?? $this->getLocale());
    }

    public function setLocale(string $locale): void
    {
        current_context()->set(ScopedService::LOCALE, $locale, replace: true);
    }

    public function getLocale(): string
    {
        return current_context()->findLocal(ScopedService::LOCALE)
            ?? $this->inner->getLocale();
    }
}
