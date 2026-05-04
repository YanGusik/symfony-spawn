<?php

namespace Spawn\Symfony\Server;

use Spawn\Symfony\Contracts\ServerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use TrueAsync\HttpServer;
use TrueAsync\HttpServerConfig;
use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;

class TrueAsyncServer implements ServerInterface
{
    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly string $host,
        private readonly int $port,
        private readonly array $options = []
    ) {}

    public function start(): void
    {
        $config = new HttpServerConfig();
        $config->addListener($this->host, $this->port);
        $config->addListener($this->host, 8443, true);

        $config->setReadTimeout($this->options['read_timeout'] ?? 60);
        $config->setWriteTimeout($this->options['write_timeout'] ?? 60);

        $server = new HttpServer($config);
        $server->addHttpHandler(function (HttpRequest $request, HttpResponse $response) {
            $request->awaitBody();

            $sfRequest = $this->convertRequest($request);
            $sfResponse = $this->kernel->handle($sfRequest);

            $this->applyResponse($sfResponse, $response);

            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($sfRequest, $sfResponse);
            }
        });

        $server->start();
    }

    private function convertRequest(HttpRequest $request): Request
    {
        $uri = $request->getUri();
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);

        $server = [
            'REQUEST_METHOD' => $request->getMethod(),
            'REQUEST_URI' => $uri,
            'SERVER_PROTOCOL' => 'HTTP/' . $request->getHttpVersion(),
            'CONTENT_TYPE' => $request->getContentType(),
            'CONTENT_LENGTH' => $request->getContentLength(),
        ];

        foreach ($request->getHeaders() as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return new Request($query, $request->getPost(), [], $this->parseCookies($request), $request->getFiles(), $server, $request->getBody());
    }

    private function applyResponse(SymfonyResponse $sfRes, HttpResponse $res): void
    {
        $res->setStatusCode($sfRes->getStatusCode());
        foreach ($sfRes->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $res->setHeader($name, $values);
        }
        foreach ($sfRes->headers->getCookies() as $cookie) {
            $res->addHeader('Set-Cookie', (string)$cookie);
        }
        $res->setBody($sfRes->getContent());
        $res->end();
    }

    private function parseCookies(HttpRequest $request): array
    {
        $header = $request->getHeader('cookie') ?? '';
        if (!$header) return [];
        $cookies = [];
        foreach (explode('; ', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) $cookies[trim($parts[0])] = trim($parts[1]);
        }
        return $cookies;
    }
}