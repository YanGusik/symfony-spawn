<?php

declare(strict_types=1);

namespace Spawn\Symfony\Runtime;

use Spawn\Symfony\Contracts\ServerInterface;
use Spawn\Symfony\Server\DevServer;
use Spawn\Symfony\Server\FrankenPhpServer;
use Spawn\Symfony\Server\TrueAsyncServer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

class TrueAsyncRuntime extends SymfonyRuntime
{
    public function getRunner(?object $application): RunnerInterface
    {
        if (!$application instanceof HttpKernelInterface) {
            return parent::getRunner($application);
        }

        $options = $this->resolveOptions();

        // Dev / FrankenPHP / no-extension fallback. Everything else — including
        // a single worker — goes through the TrueAsync HttpServer, which owns
        // its worker pool internally via HttpServerConfig::setWorkers().
        if ($options['use_dev']
            || ($_SERVER['FRANKENPHP_WORKER'] ?? false)
            || !class_exists(\TrueAsync\HttpServer::class)
        ) {
            return $this->createDevRunner($application, $options);
        }

        return $this->createServerRunner($application, $options);
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

    /**
     * @param array<string, mixed> $options
     */
    private function createDevRunner(HttpKernelInterface $kernel, array $options): RunnerInterface
    {
        $server = ($_SERVER['FRANKENPHP_WORKER'] ?? false)
            ? new FrankenPhpServer($kernel)
            : new DevServer($kernel, $options['host'], $options['port']);

        return new class($server) implements RunnerInterface {
            public function __construct(private readonly ServerInterface $server) {}
            public function run(): int { $this->server->start(); return 0; }
        };
    }

    /**
     * Build the TrueAsync HttpServer runner.
     *
     * The kernel cache is warmed once here, in the single main thread, before
     * the server's worker pool starts — otherwise every worker would race to
     * compile the container into the same var/cache directory and corrupt it.
     *
     * The kernel itself is described to the server by transfer-safe scalars
     * (class name, env, debug, env vars), never an object or closure, so the
     * config + handler replicate cleanly across the pool. Each worker builds
     * its own kernel lazily.
     *
     * @param array<string, mixed> $options
     */
    private function createServerRunner(HttpKernelInterface $kernel, array $options): RunnerInterface
    {
        $options['kernel_class'] = $kernel::class;
        $options['kernel_env']   = (string) ($_SERVER['APP_ENV'] ?? 'prod');
        $options['kernel_debug'] = (bool) ($_SERVER['APP_DEBUG'] ?? false);
        $options['env_vars']     = $this->captureEnvVars();

        return new class($options) implements RunnerInterface {
            /** @param array<string, mixed> $options */
            public function __construct(private readonly array $options) {}

            public function run(): int
            {
                $options = $this->options;

                // Warm the kernel cache once, in this single main thread,
                // before the worker pool is started.
                $kernelClass = $options['kernel_class'];
                if (is_a($kernelClass, KernelInterface::class, true)) {
                    $warmupKernel = new $kernelClass($options['kernel_env'], $options['kernel_debug']);
                    $console      = new Application($warmupKernel);
                    $console->setAutoExit(false);
                    $console->run(new ArrayInput(['command' => 'cache:warmup']), new NullOutput());
                }

                fprintf(
                    STDERR,
                    "[true-async-server] %d worker(s) on %s:%d\n",
                    (int) ($options['workers'] ?? 1),
                    $options['host'],
                    $options['port'],
                );

                $server = new TrueAsyncServer(
                    $options['host'],
                    $options['port'],
                    $options,
                    $options['autoload'],
                );

                $server->start();

                return 0;
            }
        };
    }

    /**
     * Capture only env vars that Symfony / Doctrine / the app actually need.
     * $_SERVER and $_ENV are empty in freshly spawned worker threads, so these
     * are handed to the server and restored inside each worker.
     *
     * @return array<string, scalar>
     */
    private function captureEnvVars(): array
    {
        $neededPrefixes = ['APP_', 'DATABASE_', 'DEFAULT_URI', 'SYMFONY_', 'TRUASYNC_', 'KERNEL', 'PHP_'];
        $envVars = [];

        foreach (array_merge($_ENV, $_SERVER) as $k => $v) {
            if (!is_scalar($v)) {
                continue;
            }
            foreach ($neededPrefixes as $prefix) {
                if (str_starts_with((string) $k, $prefix)) {
                    $envVars[$k] = $v;
                    break;
                }
            }
        }

        return $envVars;
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
