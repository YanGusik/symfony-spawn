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
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8080,
        private readonly array $configOptions = []
    ) {}

    public function start(): void
    {
        if (!class_exists(HttpServer::class)) {
            throw new \RuntimeException('TrueAsync extension is not loaded.');
        }

        $config = new HttpServerConfig();
        $config->addListener($this->host, $this->port);

        $config->setReadTimeout($this->configOptions['read_timeout'] ?? 30);
        $config->setWriteTimeout($this->configOptions['write_timeout'] ?? 30);

        if (isset($this->configOptions['log_severity'])) {
            $config->setLogSeverity($this->configOptions['log_severity']);
        }

        $server = new HttpServer($config);

        $server->addHttpHandler(function (HttpRequest $request, HttpResponse $response) {
            try {
                $symfonyRequest = $this->convertRequest($request);
                $symfonyResponse = $this->kernel->handle($symfonyRequest);

                $this->applyResponse($symfonyResponse, $response);

                if ($this->kernel instanceof TerminableInterface) {
                    $this->kernel->terminate($symfonyRequest, $symfonyResponse);
                }
            } catch (\Throwable $e) {
                $this->handleError($e, $response);
            }
        });

        echo "TrueAsync Symfony Server starting on http://{$this->host}:{$this->port}\n";
        $server->start();
    }

    private function convertRequest(HttpRequest $request): Request
    {
        $uri = $request->getUri();
        $parsedUrl = parse_url($uri);
        $queryString = $parsedUrl['query'] ?? '';

        $query = [];
        if ($queryString !== '') {
            parse_str($queryString, $query);
        }

        $server = [
            'REQUEST_METHOD'  => $request->getMethod(),
            'REQUEST_URI'     => $uri,
            'QUERY_STRING'    => $queryString,
            'SERVER_PROTOCOL' => 'HTTP/' . $request->getHttpVersion(),
            'CONTENT_TYPE'    => $request->getContentType(),
            'CONTENT_LENGTH'  => $request->getContentLength(),
        ];

        foreach ($request->getHeaders() as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$key] = $value;
        }

        return new Request(
            query: $query,
            request: $request->getPost(),
            attributes: [],
            cookies: $this->parseCookies($request->getHeader('cookie') ?? ''),
            files: $request->getFiles(),
            server: $server,
            content: $request->getBody()
        );
    }

    private function applyResponse(SymfonyResponse $sfRes, HttpResponse $res): void
    {
        $res->setStatusCode($sfRes->getStatusCode());

        foreach ($sfRes->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $res->setHeader($name, $values);
        }

        foreach ($sfRes->headers->getCookies() as $cookie) {
            $res->addHeader('Set-Cookie', (string) $cookie);
        }

        $res->setBody($sfRes->getContent());
        $res->end();
    }

    private function parseCookies(string $cookieHeader): array
    {
        if (!$cookieHeader) return [];
        $cookies = [];
        foreach (explode('; ', $cookieHeader) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }
        return $cookies;
    }

    private function handleError(\Throwable $e, HttpResponse $response): void
    {
        error_log("[TrueAsync Error] " . $e->getMessage());
        $response->setStatusCode(500)
            ->setHeader('Content-Type', 'text/plain')
            ->setBody("Internal Server Error\n" . $e->getMessage())
            ->end();
    }
}