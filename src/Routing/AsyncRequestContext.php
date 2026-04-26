<?php

namespace Spawn\Symfony\Routing;

use Spawn\Symfony\ScopedService;
use Symfony\Component\Routing\RequestContext;

use function Async\current_context;

/**
 * Per-coroutine RequestContext.
 *
 * RequestContext holds per-request data (host, scheme, method, path) used
 * by UrlGenerator to build absolute URLs. RouterListener calls fromRequest()
 * at the start of each request — we intercept all state into current_context()
 * so concurrent coroutines never see each other's request context.
 *
 * Boot-time defaults (from config/constructor) are stored in parent properties.
 * Per-request overrides are stored in current_context() and shadow the defaults.
 * When a coroutine hasn't set a value yet, getters fall back to parent::getXxx().
 */
class AsyncRequestContext extends RequestContext
{
    public function __construct(
        string $baseUrl = '',
        string $method = 'GET',
        string $host = 'localhost',
        string $scheme = 'http',
        int $httpPort = 80,
        int $httpsPort = 443,
        string $path = '/',
        string $queryString = '',
        ?array $parameters = null,
    ) {
        // Initialize parent properties directly (bypassing our overridden setters)
        // so parent::getXxx() fallbacks always return valid boot-time defaults.
        parent::setBaseUrl($baseUrl);
        parent::setMethod($method);
        parent::setHost($host);
        parent::setScheme($scheme);
        parent::setHttpPort($httpPort);
        parent::setHttpsPort($httpsPort);
        parent::setPathInfo($path);
        parent::setQueryString($queryString);
        parent::setParameters($parameters ?? []);
    }

    public function getBaseUrl(): string
    {
        return $this->state()['baseUrl'] ?? parent::getBaseUrl();
    }

    public function getPathInfo(): string
    {
        return $this->state()['pathInfo'] ?? parent::getPathInfo();
    }

    public function getMethod(): string
    {
        return $this->state()['method'] ?? parent::getMethod();
    }

    public function getHost(): string
    {
        return $this->state()['host'] ?? parent::getHost();
    }

    public function getScheme(): string
    {
        return $this->state()['scheme'] ?? parent::getScheme();
    }

    public function getHttpPort(): int
    {
        return $this->state()['httpPort'] ?? parent::getHttpPort();
    }

    public function getHttpsPort(): int
    {
        return $this->state()['httpsPort'] ?? parent::getHttpsPort();
    }

    public function getQueryString(): string
    {
        return $this->state()['queryString'] ?? parent::getQueryString();
    }

    public function getParameters(): array
    {
        return $this->state()['parameters'] ?? parent::getParameters();
    }

    public function getParameter(string $name): mixed
    {
        return ($this->state()['parameters'] ?? parent::getParameters())[$name] ?? null;
    }

    public function hasParameter(string $name): bool
    {
        return array_key_exists($name, $this->state()['parameters'] ?? parent::getParameters());
    }

    public function setBaseUrl(string $baseUrl): static
    {
        $this->patch('baseUrl', rtrim($baseUrl, '/'));
        return $this;
    }

    public function setPathInfo(string $pathInfo): static
    {
        $this->patch('pathInfo', $pathInfo);
        return $this;
    }

    public function setMethod(string $method): static
    {
        $this->patch('method', strtoupper($method));
        return $this;
    }

    public function setHost(string $host): static
    {
        $this->patch('host', strtolower($host));
        return $this;
    }

    public function setScheme(string $scheme): static
    {
        $this->patch('scheme', strtolower($scheme));
        return $this;
    }

    public function setHttpPort(int $httpPort): static
    {
        $this->patch('httpPort', $httpPort);
        return $this;
    }

    public function setHttpsPort(int $httpsPort): static
    {
        $this->patch('httpsPort', $httpsPort);
        return $this;
    }

    public function setQueryString(?string $queryString): static
    {
        $this->patch('queryString', (string) $queryString);
        return $this;
    }

    public function setParameters(array $parameters): static
    {
        $this->patch('parameters', $parameters);
        return $this;
    }

    public function setParameter(string $name, mixed $parameter): static
    {
        $state               = $this->state();
        $params              = $state['parameters'] ?? parent::getParameters();
        $params[$name]       = $parameter;
        $state['parameters'] = $params;
        current_context()->set(ScopedService::REQUEST_CONTEXT, $state, replace: true);
        return $this;
    }

    private function state(): array
    {
        return current_context()->findLocal(ScopedService::REQUEST_CONTEXT) ?? [];
    }

    private function patch(string $key, mixed $value): void
    {
        $state       = $this->state();
        $state[$key] = $value;
        current_context()->set(ScopedService::REQUEST_CONTEXT, $state, replace: true);
    }
}
