<?php

namespace Spawn\Symfony\Server;

use Async\Future;
use Async\FutureState;
use Async\Scope;
use Spawn\Symfony\Contracts\ServerInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

class DevServer implements ServerInterface
{
    private ?Scope $serverScope = null;

    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly string $host,
        private readonly int $port,
    ) {}

    public function __destruct()
    {
        $this->serverScope?->dispose();
    }

    public function start(): void
    {
        $shutdownState  = new FutureState();
        $shutdownFuture = (new Future($shutdownState))->ignore();

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $shutdownState->complete(null));
            pcntl_signal(SIGINT,  fn() => $shutdownState->complete(null));
        }

        $this->serverScope = new Scope();
        $serverScope = $this->serverScope;

        $serverScope->setExceptionHandler(function (Scope $scope, \Async\Coroutine $coroutine, \Throwable $e) {
            echo '[server error] ' . $e::class . ': ' . $e->getMessage() . "\n";
        });

        $serverScope->spawn(function () use ($serverScope) {
            // Pool must be warmed up inside the first coroutine — the TrueAsync
            // scheduler is not running until spawn() is called, so warming up
            // before this point would create a plain PDO, not a pooled one.
            $this->warmUpDatabasePool();

            $socket = stream_socket_server("tcp://{$this->host}:{$this->port}");

            if ($socket === false) {
                throw new \RuntimeException("Failed to bind tcp://{$this->host}:{$this->port}");
            }

            echo "Listening on tcp://{$this->host}:{$this->port}\n";

            while (true) {
                $client = @stream_socket_accept($socket, timeout: -1);

                if ($client === false) {
                    continue;
                }

                // Each request gets its own Scope so that current_context()
                // is isolated per-request. Async adapters store per-request
                // state (RequestStack, TokenStorage, etc.) in this context.
                $requestScope = Scope::inherit($serverScope);

                $requestScope->setExceptionHandler(function (\Throwable $e) {
                    echo '[request error] ' . $e::class . ': ' . $e->getMessage() . "\n";
                });

                $requestScope->spawn($this->handleConnection(...), $client, $requestScope);
            }
        });

        try {
            $serverScope->awaitCompletion($shutdownFuture);
        } catch (\Async\AsyncCancellation) {
            $serverScope->cancel();
            $this->serverScope = null;
        }
    }

    private function warmUpDatabasePool(): void
    {
        if (!$this->kernel instanceof KernelInterface) {
            return;
        }

        $container = $this->kernel->getContainer();

        if (!$container->hasParameter('doctrine.connections')) {
            return;
        }

        foreach ($container->getParameter('doctrine.connections') as $name => $serviceId) {
            if (!$container->has($serviceId)) {
                continue;
            }

            try {
                $container->get($serviceId)->connect();
                echo "[async] DB pool warmed up: {$name}\n";
            } catch (\Throwable $e) {
                echo "[async] DB pool warm-up ({$name}) failed: {$e->getMessage()}\n";
            }
        }
    }

    private function handleConnection(mixed $client, Scope $requestScope): void
    {
        try {
            $raw = $this->readRaw($client);

            if ($raw === '') {
                return;
            }

            $request  = RequestParser::parse($raw);
            $response = $this->kernel->handle($request);

            ResponseEmitter::emit($client, $response);

            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($request, $response);
            }
        } finally {
            $requestScope->dispose();
            fclose($client);
        }
    }

    private function readRaw(mixed $client): string
    {
        $raw = '';

        while ($chunk = fread($client, 8192)) {
            $raw .= $chunk;

            if (str_contains($raw, "\r\n\r\n")) {
                if (preg_match('/Content-Length:\s*(\d+)/i', $raw, $m)) {
                    $headerEnd  = strpos($raw, "\r\n\r\n") + 4;
                    $bodyLength = (int) $m[1];
                    $bodyRead   = strlen($raw) - $headerEnd;

                    while ($bodyRead < $bodyLength) {
                        $chunk = fread($client, $bodyLength - $bodyRead);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $raw      .= $chunk;
                        $bodyRead += strlen($chunk);
                    }
                }

                break;
            }
        }

        return $raw;
    }
}
