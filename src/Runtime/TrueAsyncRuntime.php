<?php

declare(strict_types=1);

namespace Spawn\Symfony\Runtime;

use Spawn\Symfony\Contracts\ServerInterface;
use Spawn\Symfony\Server\DevServer;
use Spawn\Symfony\Server\FrankenPhpServer;
use Spawn\Symfony\Server\TrueAsyncServer;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;
use function Async\await;
use function Async\await_all;
use function Async\spawn;
use function Async\spawn_thread;

class TrueAsyncRuntime extends SymfonyRuntime
{
    public function getRunner(?object $application): RunnerInterface
    {
        if (!$application instanceof HttpKernelInterface) {
            return parent::getRunner($application);
        }

        $options = $this->resolveOptions();

        // Dev / FrankenPHP / single-threaded fallback
        if ($options['use_dev']
            || ($_SERVER['FRANKENPHP_WORKER'] ?? false)
            || !class_exists(\TrueAsync\HttpServer::class)
            || $options['workers'] <= 1
        ) {
            return $this->createDevRunner($application, $options);
        }

        return $this->createMultiThreadedRunner($application, $options);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveOptions(): array
    {
        // Symfony Runtime wraps extra.runtime.options under $this->options['options']
        $runtimeOptions = (array) ($this->options['options'] ?? []);

        $opts = array_merge($this->options, $runtimeOptions);

        $workers = (int) ($opts['workers'] ?? 0);
        if ($workers <= 0) {
            $workers = $this->detectCoreCount();
        }

        return [
            'host'            => (string) ($opts['host'] ?? '0.0.0.0'),
            'port'            => (int) ($opts['port'] ?? 8080),
            'workers'         => $workers,
            'autoload'        => (string) ($opts['autoload'] ?? ($opts['project_dir'] ?? (string) getcwd()) . '/vendor/autoload.php'),
            'use_dev'         => (bool) ($opts['use_dev'] ?? false),
            'listeners'       => (array) ($opts['listeners'] ?? []),
            'static_handlers' => (array) ($opts['static_handlers'] ?? []),
            'tls_cert'        => (string) ($opts['tls_cert'] ?? $_SERVER['TLS_CERT'] ?? '/certs/server.crt'),
            'tls_key'         => (string) ($opts['tls_key'] ?? $_SERVER['TLS_KEY'] ?? '/certs/server.key'),
            'backlog'         => (int) ($opts['backlog'] ?? 2048),
            'compression'     => (bool) ($opts['compression'] ?? true),
            'max_body_size'   => (int) ($opts['max_body_size'] ?? 32 * 1024 * 1024),
            'read_timeout'    => (int) ($opts['read_timeout'] ?? 60),
            'write_timeout'   => (int) ($opts['write_timeout'] ?? 60),
        ];
    }

    private function createDevRunner(HttpKernelInterface $kernel, array $options): RunnerInterface
    {
        if ($_SERVER['FRANKENPHP_WORKER'] ?? false) {
            $server = new FrankenPhpServer($kernel);
            return new class($server) implements RunnerInterface {
                public function __construct(private readonly ServerInterface $server) {}
                public function run(): int { $this->server->start(); return 0; }
            };
        }

        if ($options['use_dev'] || !class_exists(\TrueAsync\HttpServer::class)) {
            $server = new DevServer($kernel, $options['host'], $options['port']);
            return new class($server) implements RunnerInterface {
                public function __construct(private readonly ServerInterface $server) {}
                public function run(): int { $this->server->start(); return 0; }
            };
        }

        // Single-threaded TrueAsync (1 worker)
        $kernelFactory = static fn(): HttpKernelInterface => $kernel;
        $server = new TrueAsyncServer($options['host'], $options['port'], $options, $kernelFactory);

        return new class($server) implements RunnerInterface {
            public function __construct(private readonly ServerInterface $server) {}
            public function run(): int { $this->server->start(); return 0; }
        };
    }

    private function createMultiThreadedRunner(HttpKernelInterface $kernel, array $options): RunnerInterface
    {
        $kernelClass  = get_class($kernel);
        $env          = $_SERVER['APP_ENV'] ?? 'prod';
        $debug        = (bool) ($_SERVER['APP_DEBUG'] ?? false);
        $autoloadPath = $options['autoload'];

        // Capture only env vars that Symfony / Doctrine / app actually need.
        // ($_SERVER and $_ENV are empty in spawned threads)
        $neededPrefixes = ['APP_', 'DATABASE_', 'DEFAULT_URI', 'SYMFONY_', 'TRUASYNC_', 'KERNEL', 'PHP_'];
        $envVars = [];
        foreach (array_merge($_ENV, $_SERVER) as $k => $v) {
            if (!is_scalar($v) && $v !== null) {
                continue;
            }
            foreach ($neededPrefixes as $prefix) {
                if (str_starts_with((string) $k, $prefix)) {
                    $envVars[$k] = $v;
                    break;
                }
            }
        }

        return new class($options, $kernelClass, $env, $debug, $autoloadPath, $envVars) implements RunnerInterface {
            public function __construct(
                private readonly array $options,
                private readonly string $kernelClass,
                private readonly string $env,
                private readonly bool $debug,
                private readonly string $autoloadPath,
                private readonly array $envVars,
            ) {
            }

            public function run(): int
            {
                $workersCount = $this->options['workers'];
                $host         = $this->options['host'];
                $port         = $this->options['port'];
                $options      = $this->options;
                $envVars      = $this->envVars;
                $autoloadPath = $this->autoloadPath;
                $kernelClass  = $this->kernelClass;
                $env          = $this->env;
                $debug        = $this->debug;

                fprintf(
                    STDERR,
                    "[true-async-server] Starting %d workers on %s:%d...\n",
                    $workersCount,
                    $host,
                    $port
                );

                $mainCoroutine = spawn(function () use ($workersCount, $host, $port, $options, $envVars, $autoloadPath, $kernelClass, $env, $debug): void {
                    $threads = [];

                    for ($i = 0; $i < $workersCount; $i++) {
                        $threads[] = spawn_thread(
                            // Closures handed to spawn_thread() are transferred to a fresh
                            // worker thread; they must be static so they do not carry $this
                            // (the anonymous runner class is undefined in the worker).
                            task: static function () use ($envVars, $autoloadPath, $kernelClass, $env, $debug, $host, $port, $options): void {
                                // Restore environment inside worker thread
                                foreach ($envVars as $k => $v) {
                                    if ($v !== null) {
                                        $_SERVER[$k] = $v;
                                        $_ENV[$k]    = $v;
                                        putenv("{$k}={$v}");
                                    }
                                }

                                if (file_exists($autoloadPath)) {
                                    require_once $autoloadPath;
                                }

                                $kernelFactory = function () use ($kernelClass, $env, $debug): HttpKernelInterface {
                                    $kernel = new $kernelClass($env, $debug);
                                    if ($kernel instanceof KernelInterface) {
                                        $kernel->boot();
                                    }
                                    return $kernel;
                                };

                                $server = new TrueAsyncServer(
                                    $host,
                                    $port,
                                    $options,
                                    $kernelFactory
                                );

                                $server->start();
                            },
                            bootloader: static function () use ($autoloadPath): void {
                                if (file_exists($autoloadPath)) {
                                    require_once $autoloadPath;
                                }
                            }
                        );
                    }

                    await_all($threads);
                });

                await($mainCoroutine);

                return 0;
            }
        };
    }

    private function detectCoreCount(): int
    {
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            return count($matches[0]) ?: 1;
        }

        return 1;
    }
}
