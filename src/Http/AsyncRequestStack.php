<?php

namespace Spawn\Symfony\Http;

use Spawn\Symfony\ScopedService;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function Async\current_context;

/**
 * Per-coroutine RequestStack.
 *
 * Stores the request stack in current_context() so each coroutine (request)
 * gets its own isolated stack. The parent $requests property is never used.
 */
class AsyncRequestStack extends RequestStack
{
    public function push(Request $request): void
    {
        $requests   = $this->getRequests();
        $requests[] = $request;
        current_context()->set(ScopedService::REQUEST_STACK, $requests, replace: true);
    }

    public function pop(): ?Request
    {
        $requests = $this->getRequests();

        if (!$requests) {
            return null;
        }

        $request = array_pop($requests);
        current_context()->set(ScopedService::REQUEST_STACK, $requests, replace: true);

        return $request;
    }

    public function getCurrentRequest(): ?Request
    {
        $requests = $this->getRequests();

        return end($requests) ?: null;
    }

    public function getMainRequest(): ?Request
    {
        return $this->getRequests()[0] ?? null;
    }

    public function getParentRequest(): ?Request
    {
        $requests = $this->getRequests();
        $pos      = \count($requests) - 2;

        return $requests[$pos] ?? null;
    }

    public function getSession(): SessionInterface
    {
        $requests = $this->getRequests();

        if ((null !== $request = end($requests) ?: null) && $request->hasSession()) {
            return $request->getSession();
        }

        throw new SessionNotFoundException();
    }

    private function getRequests(): array
    {
        // findLocal() — only current scope, so each coroutine starts with an empty stack.
        return current_context()->findLocal(ScopedService::REQUEST_STACK) ?? [];
    }
}
