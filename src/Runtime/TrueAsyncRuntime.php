<?php

namespace Spawn\Symfony\Runtime;

use Spawn\Symfony\Contracts\ServerInterface;
use Spawn\Symfony\Server\DevServer;
use Spawn\Symfony\Server\FrankenPhpServer;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * TrueAsync runtime for Symfony.
 *
 * Set via APP_RUNTIME env var or composer.json extra.runtime.class.
 * Detects FrankenPHP worker mode automatically; falls back to DevServer.
 *
 * Options (via APP_RUNTIME_OPTIONS or composer.json extra.runtime):
 *   host  — bind address for DevServer (default: 127.0.0.1)
 *   port  — bind port for DevServer (default: 8080)
 */
class TrueAsyncRuntime extends SymfonyRuntime
{
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof HttpKernelInterface) {
            $server = $this->createServer($application);

            return new class($server) implements RunnerInterface {
                public function __construct(private readonly ServerInterface $server) {}

                public function run(): int
                {
                    $this->server->prepareApp();
                    $this->server->start();

                    return 0;
                }
            };
        }

        return parent::getRunner($application);
    }

    private function createServer(HttpKernelInterface $kernel): ServerInterface
    {
        if ($_SERVER['FRANKENPHP_WORKER'] ?? false) {
            return new FrankenPhpServer($kernel);
        }

        return new DevServer(
            $kernel,
            (string) ($this->options['host'] ?? '127.0.0.1'),
            (int)    ($this->options['port'] ?? 8080),
        );
    }
}
