<?php

namespace Spawn\Symfony\Server;

use FrankenPHP\HttpServer as FrankenHttpServer;
use FrankenPHP\Request as FrankenRequest;
use FrankenPHP\Response as FrankenResponse;
use Spawn\Symfony\Contracts\ServerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

class FrankenPhpServer implements ServerInterface
{
    public function __construct(
        private readonly HttpKernelInterface $kernel,
    ) {}

    public function start(): void
    {
        if (!class_exists(FrankenHttpServer::class)) {
            throw new \RuntimeException(
                'FrankenPHP extension is not available. ' .
                'Make sure you are running under the TrueAsync FrankenPHP server.'
            );
        }

        // Worker mode: onRequest() blocks indefinitely — disable execution time limit.
        set_time_limit(0);

        // NOTE: DB pool warm-up is NOT called here.
        // The TrueAsync scheduler starts only when onRequest() is called.
        // Pool initialization happens lazily on the first DB access inside a coroutine.

        FrankenHttpServer::onRequest(function (FrankenRequest $frankenRequest, FrankenResponse $frankenResponse) {
            try {
                $request  = $this->buildRequest($frankenRequest);
                $response = $this->kernel->handle($request);

                $this->sendResponse($frankenResponse, $response);

                if ($this->kernel instanceof TerminableInterface) {
                    $this->kernel->terminate($request, $response);
                }
            } catch (\Throwable $e) {
                error_log('[async:franken] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

                $frankenResponse->setStatus(500);
                $frankenResponse->setHeader('Content-Type', 'text/plain');
                $frankenResponse->write($e->getMessage());
                $frankenResponse->end();
            }
        });
    }

    // TrueAsync FrankenPHP does not populate $_SERVER, build the request manually.
    private function buildRequest(FrankenRequest $frankenRequest): Request
    {
        $uri     = $frankenRequest->getUri();
        $method  = strtoupper($frankenRequest->getMethod());
        $headers = $frankenRequest->getHeaders();
        $body    = $frankenRequest->getBody();

        $parsedUrl   = parse_url($uri);
        $path        = $parsedUrl['path'] ?? '/';
        $queryString = $parsedUrl['query'] ?? '';

        $queryParams = [];
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
        }

        // Parse SERVER_NAME and SERVER_PORT from the Host header.
        // FrankenPHP/Caddy strips the port from the Host header before passing to PHP,
        // so fall back to 80 when no port is present.
        $hostHeader = $headers['HOST'] ?? 'localhost';
        if (str_contains($hostHeader, ':')) {
            [$serverName, $serverPort] = explode(':', $hostHeader, 2);
        } else {
            $serverName = $hostHeader;
            $serverPort = '80';
        }

        $remoteAddr = '127.0.0.1';
        $remotePort = '';
        $rawRemote  = $frankenRequest->getRemoteAddr();
        if ($rawRemote !== '') {
            if (($lastColon = strrpos($rawRemote, ':')) !== false) {
                $remoteAddr = substr($rawRemote, 0, $lastColon);
                $remotePort = substr($rawRemote, $lastColon + 1);
            } else {
                $remoteAddr = $rawRemote;
            }
        }

        $server = [
            'REQUEST_METHOD'  => $method,
            'REQUEST_URI'     => $path . ($queryString !== '' ? '?' . $queryString : ''),
            'QUERY_STRING'    => $queryString,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME'     => $serverName,
            'SERVER_PORT'     => $serverPort,
            'REMOTE_ADDR'     => $remoteAddr,
            'REMOTE_PORT'     => $remotePort,
        ];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            match ($key) {
                'CONTENT_TYPE'   => $server['CONTENT_TYPE'] = $value,
                'CONTENT_LENGTH' => $server['CONTENT_LENGTH'] = $value,
                'HOST'           => $server['HTTP_HOST'] = $value,
                default          => $server['HTTP_' . $key] = $value,
            };
        }

        // Caddy strips the port from the Host header — restore it so Symfony's
        // Request::getPort() / getHttpHost() return the correct value.
        if ($serverPort !== '80') {
            $server['HTTP_HOST'] = $serverName . ':' . $serverPort;
        }

        $cookies      = [];
        $cookieHeader = $headers['COOKIE'] ?? '';
        if ($cookieHeader !== '') {
            foreach (explode('; ', $cookieHeader) as $pair) {
                $parts = explode('=', $pair, 2);
                if (count($parts) === 2) {
                    $cookies[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        $postData    = [];
        $contentType = $headers['CONTENT_TYPE'] ?? '';
        if ($method === 'POST' && str_contains($contentType, 'application/x-www-form-urlencoded') && $body !== '') {
            parse_str($body, $postData);
        }

        return Request::create(
            uri: $server['REQUEST_URI'],
            method: $method,
            parameters: in_array($method, ['GET', 'HEAD']) ? $queryParams : $postData,
            cookies: $cookies,
            files: [],
            server: $server,
            content: $body !== '' ? $body : null,
        );
    }

    private function sendResponse(FrankenResponse $frankenResponse, SymfonyResponse $response): void
    {
        $frankenResponse->setStatus($response->getStatusCode());

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                if ($first) {
                    $frankenResponse->setHeader($name, $value);
                    $first = false;
                } else {
                    $frankenResponse->addHeader($name, $value);
                }
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $frankenResponse->addHeader('Set-Cookie', (string) $cookie);
        }

        $frankenResponse->write((string) $response->getContent());
        $frankenResponse->end();
    }
}
