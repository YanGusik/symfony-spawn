<?php

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
use function Async\spawn_thread;
use function Async\await_all;
use function Async\spawn;

class TrueAsyncRuntime extends SymfonyRuntime
{
    public function getRunner(?object $application): RunnerInterface
    {
        if ($application instanceof HttpKernelInterface) {
            $options = [
                'host' => (string) ($this->options['host'] ?? '0.0.0.0'),
                'port' => (int) ($this->options['port'] ?? 8080),
                'workers' => (int) ($this->options['workers'] ?? $this->detectCoreCount()),
                'autoload' => (string) ($this->options['project_dir'] . '/vendor/autoload.php'),
                'use_dev' => (bool) ($this->options['use_dev'] ?? false),
            ];

            return new class($application, $options, $this) implements RunnerInterface {
                public function __construct(
                    private readonly HttpKernelInterface $kernel,
                    private readonly array $options,
                    private readonly TrueAsyncRuntime $runtime
                ) {}

                public function run(): int
                {
                    if ($this->options['use_dev'] || !class_exists('TrueAsync\HttpServer') || $this->options['workers'] <= 1) {
                        $server = $this->runtime->createServer($this->kernel, $this->options);
                        $server->start();
                        return 0;
                    }

                    $workersCount = $this->options['workers'];
                    $autoloadPath = $this->options['autoload'];
                    $options = $this->options;
                    $runtime = $this->runtime;
                    $kernelClass = get_class($this->kernel);
                    $env = $_SERVER['APP_ENV'] ?? 'prod';
                    $debug = (bool) ($_SERVER['APP_DEBUG'] ?? false);

                    $bootloader = function () use ($autoloadPath) {
                        if (file_exists($autoloadPath)) {
                            require_once $autoloadPath;
                        }
                    };

                    $mainCoroutine = spawn(function () use ($workersCount, $bootloader, $runtime, $options, $kernelClass, $env, $debug) {
                        $threads = [];
                        echo "Starting TrueAsync Cluster with $workersCount threads on {$options['host']}:{$options['port']}...\n";

                        for ($i = 0; $i < $workersCount; $i++) {
                            $threads[] = spawn_thread(
                                task: function () use ($runtime, $options, $kernelClass, $env, $debug) {
                                    if (class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
                                        $projectDir = $options['project_dir'] ?? getcwd();
                                        if (file_exists($projectDir . '/.env')) {
                                            (new \Symfony\Component\Dotenv\Dotenv())->bootEnv($projectDir . '/.env');
                                        }
                                    }


                                    $kernel = new $kernelClass($env, $debug);
                                    if ($kernel instanceof KernelInterface) {
                                        $kernel->boot();
                                    }

                                    $server = new TrueAsyncServer($kernel, $options['host'], $options['port'], $options);
                                    $server->start();
                                },
                                bootloader: $bootloader
                            );
                        }

                        await_all($threads);
                    });

                    await($mainCoroutine);

                    return 0;
                }
            };
        }

        return parent::getRunner($application);
    }

    public function createServer(HttpKernelInterface $kernel, array $options): ServerInterface
    {
        if ($_SERVER['FRANKENPHP_WORKER'] ?? false) {
            return new FrankenPhpServer($kernel);
        }

        if ($options['use_dev'] || !class_exists('TrueAsync\HttpServer')) {
            return new DevServer($kernel, $options['host'], $options['port']);
        }

        echo "Start TrueAsyncServer with 1 worker on {$options['host']}:{$options['port']}...\n";
        return new TrueAsyncServer($kernel, $options['host'], $options['port'], $options);
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