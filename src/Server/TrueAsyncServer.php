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
 * The worker pool is owned by the TrueAsync HttpServer itself: a worker count
 * greater than one is handed to HttpServerConfig::setWorkers(), and start()
 * spawns the pool, re-binds the listeners under SO_REUSEPORT and replicates
 * the config + handler to every worker. This adapter therefore never spawns
 * threads on its own.
 *
 * The request handler MUST be a free `static` closure capturing only scalars:
 * the pool replicates it to each worker via transfer_obj, and that path does
 * not support closures bound to an object (`$this`) — unlike spawn_thread, it
 * crashes on them. The kernel is therefore described by class name + env and
 * built lazily, once per worker, inside the handler.
 */
final class TrueAsyncServer implements ServerInterface
{
    /**
     * The $options array describes the kernel with transfer-safe scalars:
     *
     *   kernel_class  string                  Kernel class name
     *   kernel_env    string                  Kernel environment (default "prod")
     *   kernel_debug  bool                    Kernel debug flag
     *   env_vars      array<string, scalar>   Env vars to restore inside the worker
     *
     * @param string               $host         Bind host
     * @param int                  $port         Primary port (fallback when listeners not configured)
     * @param array<string, mixed> $options      Server + kernel configuration
     * @param string|null          $autoloadPath Composer autoloader path, required by worker threads
     */
    public function __construct(
        private readonly string  $host,
        private readonly int     $port,
        private readonly array   $options = [],
        private readonly ?string $autoloadPath = null,
    ) {
    }

    public function start(): void
    {
        if (empty($this->options['kernel_class'])) {
            throw new \LogicException('TrueAsyncServer requires options[kernel_class] in order to start.');
        }

        $this->run();
    }

    /**
     * Build and run the HttpServer.
     */
    private function run(): void
    {
        try {
            $config = $this->buildConfig();
            $server = new HttpServer($config);

            $this->registerStaticHandlers($server);

            // Everything the handler needs, captured by value as scalars — the
            // closure stays a free static closure so the worker pool can
            // replicate it. See the class docblock.
            $host        = $this->host;
            $port        = $this->port;
            $kernelClass = (string) $this->options['kernel_class'];
            $kernelEnv   = (string) ($this->options['kernel_env'] ?? 'prod');
            $kernelDebug = (bool) ($this->options['kernel_debug'] ?? false);
            $envVars     = (array) ($this->options['env_vars'] ?? []);

            $server->addHttpHandler(static function (HttpRequest $request, HttpResponse $response)
                use ($host, $port, $kernelClass, $kernelEnv, $kernelDebug, $envVars): void
            {
                // One kernel per worker, built on the first request and reused.
                // Referenced by class name, not self:: — a transferred closure
                // carries no class scope.
                static $kernel = null;

                if ($kernel === null) {
                    $kernel = TrueAsyncServer::bootKernel($kernelClass, $kernelEnv, $kernelDebug, $envVars);
                }

                $request->awaitBody();

                $sfRequest  = TrueAsyncServer::convertRequest($request, $host, $port);
                $sfResponse = $kernel->handle($sfRequest);

                TrueAsyncServer::applyResponse($sfResponse, $response);

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

    /**
     * Build and boot the kernel inside a worker. $_SERVER / $_ENV are empty in
     * freshly spawned worker threads, so the captured env vars are restored first.
     *
     * Public only so the transferred handler closure — which has no class
     * scope — can reach it by class name.
     *
     * @param array<string, scalar> $envVars
     */
    public static function bootKernel(string $kernelClass, string $env, bool $debug, array $envVars): HttpKernelInterface
    {
        foreach ($envVars as $name => $value) {
            if ($value !== null) {
                $_SERVER[$name] = $value;
                $_ENV[$name]    = $value;
                putenv("{$name}={$value}");
            }
        }

        $kernel = new $kernelClass($env, $debug);

        if (!$kernel instanceof HttpKernelInterface) {
            throw new \LogicException('options[kernel_class] must name an HttpKernelInterface implementation.');
        }

        if ($kernel instanceof KernelInterface) {
            $kernel->boot();
        }

        return $kernel;
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

        // Worker pool: HttpServer::start() spawns and supervises it. With more
        // than one worker the config + handler are replicated to each worker,
        // and the bootloader registers the composer autoloader there before
        // the handler — and its lazily-built kernel — are first touched.
        $workers = (int) ($this->options['workers'] ?? 1);
        if ($workers > 0) {
            $config->setWorkers($workers);
        }

        if ($workers > 1 && $this->autoloadPath !== null) {
            $autoloadPath = $this->autoloadPath;
            $config->setBootloader(static function () use ($autoloadPath): void {
                if (is_file($autoloadPath)) {
                    require_once $autoloadPath;
                }
            });
        }

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

    public static function convertRequest(HttpRequest $request, string $host, int $port): Request
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
            'SERVER_NAME'       => $host,
            'SERVER_PORT'       => $port,
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
            self::parseCookies($request),
            $request->getFiles(),
            $server,
            $request->getBody()
        );
    }

    public static function applyResponse(SymfonyResponse $sfRes, HttpResponse $res): void
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

    /**
     * @return array<string, string>
     */
    private static function parseCookies(HttpRequest $request): array
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
