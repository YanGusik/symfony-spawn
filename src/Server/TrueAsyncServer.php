<?php

declare(strict_types=1);

namespace Spawn\Symfony\Server;

use Spawn\Symfony\Contracts\ServerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use TrueAsync\HttpServer;
use TrueAsync\HttpServerConfig;
use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;
use TrueAsync\StaticHandler;
use TrueAsync\StaticOnMissing;

/**
 * TrueAsync HTTP server adapter for Symfony.
 *
 * Supports both single-threaded (dev) and multi-threaded (production) modes.
 * In multi-threaded mode the kernel is created via a factory inside each worker.
 */
final class TrueAsyncServer implements ServerInterface
{
    /**
     * @param string                               $host          Bind host
     * @param int                                  $port          Primary port (fallback when listeners not configured)
     * @param array<string, mixed>                 $options       Server configuration (listeners, TLS, static handlers, etc.)
     * @param \Closure|null                        $kernelFactory  Factory returning HttpKernelInterface; required for start()
     */
    public function __construct(
        private readonly string       $host,
        private readonly int          $port,
        private readonly array        $options = [],
        private readonly ?\Closure    $kernelFactory = null,
    ) {
    }

    public function start(): void
    {
        if ($this->kernelFactory === null) {
            throw new \LogicException('TrueAsyncServer requires a kernelFactory in order to start.');
        }

        $kernel = ($this->kernelFactory)();

        if (!$kernel instanceof HttpKernelInterface) {
            throw new \LogicException('kernelFactory must return an instance of HttpKernelInterface.');
        }

        if ($kernel instanceof KernelInterface) {
            $kernel->boot();
        }

        $this->run($kernel);
    }

    /**
     * Build and run the HttpServer with the given Symfony kernel.
     */
    private function run(HttpKernelInterface $kernel): void
    {
        try {
            $config = $this->buildConfig();
            $server = new HttpServer($config);

            $this->registerStaticHandlers($server);

            $server->addHttpHandler(function (HttpRequest $request, HttpResponse $response) use ($kernel): void {
                $request->awaitBody();

                $sfRequest  = $this->convertRequest($request);
                $sfResponse = $kernel->handle($sfRequest);

                $this->applyResponse($sfResponse, $response);

                if ($kernel instanceof TerminableInterface) {
                    $kernel->terminate($sfRequest, $sfResponse);
                }
            });

            $server->start();
        } catch (\Throwable $e) {
            fwrite(STDERR, "\n!!! FATAL SERVER ERROR !!!\n");
            fwrite(STDERR, 'Message: ' . $e->getMessage() . "\n");
            fwrite(STDERR, 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n");
            fwrite(STDERR, "Trace:\n" . $e->getTraceAsString() . "\n");

            sleep(2);
            exit(1);
        }
    }

    private function buildConfig(): HttpServerConfig
    {
        $config = new HttpServerConfig();

        $listeners = $this->options['listeners'] ?? [];

        // Fallback: if no listeners configured, use legacy host+port
        if ($listeners === []) {
            $listeners = [
                ['host' => $this->host, 'port' => $this->port, 'tls' => false],
            ];
        }

        $hasTlsListeners = false;
        foreach ($listeners as $listener) {
            if (!empty($listener['tls'])) {
                $hasTlsListeners = true;
            }
        }

        $certPath = $this->options['tls_cert'] ?? $_SERVER['TLS_CERT'] ?? '/certs/server.crt';
        $keyPath  = $this->options['tls_key']  ?? $_SERVER['TLS_KEY']  ?? '/certs/server.key';
        $hasCert  = is_readable($certPath) && is_readable($keyPath);

        if ($hasTlsListeners && !$hasCert) {
            fwrite(STDERR, "[true-async-server] TLS listeners configured but certificates not found (cert: {$certPath}, key: {$keyPath}). Skipping TLS listeners.\n");
        }

        if ($hasTlsListeners && $hasCert) {
            $config->setCertificate($certPath);
            $config->setPrivateKey($keyPath);
        }

        foreach ($listeners as $listener) {
            $host     = $listener['host'] ?? '0.0.0.0';
            $port     = (int) ($listener['port'] ?? 8080);
            $tls      = !empty($listener['tls']);
            $protocol = $listener['protocol'] ?? 'auto';

            // Skip TLS listeners when certificates are missing
            if ($tls && !$hasCert) {
                continue;
            }

            match ($protocol) {
                'auto'   => $config->addListener($host, $port, $tls),
                'http1'  => $config->addHttp1Listener($host, $port, $tls),
                'http2'  => $config->addHttp2Listener($host, $port, $tls),
                'http3'  => $config->addHttp3Listener($host, $port),
                default  => throw new \InvalidArgumentException("Unknown listener protocol: {$protocol}"),
            };
        }

        $config->setBacklog((int) ($this->options['backlog'] ?? 2048));
        $config->setMaxBodySize((int) ($this->options['max_body_size'] ?? 32 * 1024 * 1024));
        $config->setReadTimeout((int) ($this->options['read_timeout'] ?? 60));
        $config->setWriteTimeout((int) ($this->options['write_timeout'] ?? 60));
        $config->setCompressionEnabled((bool) ($this->options['compression'] ?? true));

        return $config;
    }

    private function registerStaticHandlers(HttpServer $server): void
    {
        foreach ($this->options['static_handlers'] ?? [] as $sh) {
            $prefix = $sh['prefix'] ?? '/static/';
            $root   = $sh['root'] ?? '/data/static';

            if (!is_dir($root)) {
                continue;
            }

            $handler = new StaticHandler($prefix, $root);

            if (!empty($sh['precompressed'])) {
                $handler->enablePrecompressed(...$sh['precompressed']);
            }

            if (!empty($sh['etag'])) {
                $handler->setEtagEnabled(true);
            }

            if (isset($sh['open_file_cache'])) {
                $cache = $sh['open_file_cache'];
                $maxEntries = (int) ($cache[0] ?? 1024);
                $ttl        = (int) ($cache[1] ?? 60);
                $handler->setOpenFileCache($maxEntries, $ttl);
            }

            $onMissing = ($sh['on_missing'] ?? 'not_found') === 'next'
                ? StaticOnMissing::NEXT
                : StaticOnMissing::NOT_FOUND;

            $handler->setOnMissing($onMissing);

            $server->addStaticHandler($handler);
        }
    }

    private function convertRequest(HttpRequest $request): Request
    {
        $uri    = $request->getUri();
        $path   = $request->getPath();
        $method = $request->getMethod();
        $query  = $request->getQuery();

        $server = [
            'REQUEST_METHOD'    => $method,
            'REQUEST_URI'       => $uri,
            'PATH_INFO'         => $path,
            'QUERY_STRING'      => http_build_query($query),
            'SERVER_PROTOCOL'   => 'HTTP/' . $request->getHttpVersion(),
            'SERVER_NAME'       => $this->host,
            'SERVER_PORT'       => $this->port,
            'DOCUMENT_URI'      => $path,
            'SCRIPT_NAME'       => '',
            'SCRIPT_FILENAME'   => '',
            'REMOTE_ADDR'       => '127.0.0.1',
            'CONTENT_TYPE'      => $request->getContentType() ?? '',
            'CONTENT_LENGTH'    => $request->getContentLength() ?? '',
        ];

        if ($request->hasHeader('host')) {
            $server['HTTP_HOST'] = $request->getHeader('host');
        }

        foreach ($request->getHeaders() as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return new Request(
            $query,
            $request->getPost(),
            [],
            $this->parseCookies($request),
            $request->getFiles(),
            $server,
            $request->getBody()
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

        $res->setBody($sfRes->getContent() ?? '');
        $res->end();
    }

    private function parseCookies(HttpRequest $request): array
    {
        $header = $request->getHeader('cookie') ?? '';
        if ($header === '') {
            return [];
        }

        $cookies = [];
        foreach (explode('; ', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $cookies;
    }
}
