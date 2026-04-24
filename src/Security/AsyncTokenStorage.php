<?php

namespace Spawn\Symfony\Security;

use Spawn\Symfony\ScopedService;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

use function Async\current_context;

/**
 * Per-coroutine TokenStorage.
 *
 * Each coroutine (request) gets its own token and initializer stored in current_context().
 * The parent $token and $initializer properties are never used.
 */
class AsyncTokenStorage extends TokenStorage
{
    public function getToken(): ?TokenInterface
    {
        $initializer = current_context()->findLocal(ScopedService::SECURITY_INITIALIZER);

        if ($initializer !== null) {
            current_context()->set(ScopedService::SECURITY_INITIALIZER, null, replace: true);
            $initializer();
        }

        return current_context()->findLocal(ScopedService::SECURITY_TOKEN);
    }

    public function setToken(?TokenInterface $token): void
    {
        if ($token) {
            // Ensure any lazy initializer is called before overwriting.
            $this->getToken();
        }

        current_context()->set(ScopedService::SECURITY_INITIALIZER, null, replace: true);
        current_context()->set(ScopedService::SECURITY_TOKEN, $token, replace: true);
    }

    public function setInitializer(?callable $initializer): void
    {
        $closure = null === $initializer ? null : $initializer(...);
        current_context()->set(ScopedService::SECURITY_INITIALIZER, $closure, replace: true);
    }

    public function reset(): void
    {
        $this->setToken(null);
    }
}
