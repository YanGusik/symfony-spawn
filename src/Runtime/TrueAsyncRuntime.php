<?php

namespace Spawn\Symfony\Runtime;

use Spawn\Symfony\Contracts\ServerInterface;
use Spawn\Symfony\Server\DevServer;
use Spawn\Symfony\Server\FrankenPhpServer;
use Spawn\Symfony\Server\TrueAsyncServer;
use Symfony\Component\HttpKernel\HttpKernelInterface;
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

            $server = $this->createServer($application, $options);

            return new class($server, $options) implements RunnerInterface {
                public function __construct(
                    private readonly ServerInterface $server,
                    private readonly array $options
                ) {}

                public function run(): int
                {
                    // Если это не TrueAsyncServer (например DevServer), запускаем напрямую
                    if (!$this->server instanceof TrueAsyncServer || $this->options['workers'] <= 1) {
                        $this->server->start();
                        return 0;
                    }

                    $workersCount = $this->options['workers'];
                    $autoloadPath = $this->options['autoload'];

                    $bootloader = function () use ($autoloadPath) {
                        if (file_exists($autoloadPath)) {
                            require_once $autoloadPath;
                        }
                    };

                    $mainSp = spawn(function () use ($workersCount, $bootloader) {
                        $threads = [];
                        echo "Starting TrueAsync Cluster with $workersCount threads on {$this->options['host']}:{$this->options['port']}...\n";

                        for ($i = 0; $i < $workersCount; $i++) {
                            $threads[] = spawn_thread(
                                task: function () {
                                    $this->server->start();
                                },
                                bootloader: $bootloader
                            );
                        }

                        await_all($threads);
                    });

                    await($mainSp);

                    return 0;
                }
            };
        }

        return parent::getRunner($application);
    }

    private function createServer(HttpKernelInterface $kernel, array $options): ServerInterface
    {
        if ($_SERVER['FRANKENPHP_WORKER'] ?? false) {
            return new FrankenPhpServer($kernel);
        }

        if ($options['use_dev'] || !class_exists('TrueAsync\HttpServer')) {
            return new DevServer($kernel, $options['host'], $options['port']);
        }

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