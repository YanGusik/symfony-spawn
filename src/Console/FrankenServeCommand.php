<?php

namespace Spawn\Symfony\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'async:franken', description: 'Start the TrueAsync FrankenPHP server')]
class FrankenServeCommand extends Command
{
    public function __construct(private readonly KernelInterface $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host',    null, InputOption::VALUE_OPTIONAL, 'Host to listen on', '0.0.0.0')
            ->addOption('port',    null, InputOption::VALUE_OPTIONAL, 'Port to listen on', '8080')
            ->addOption('workers', null, InputOption::VALUE_OPTIONAL, 'Number of PHP worker threads', '1')
            ->addOption('buffer',  null, InputOption::VALUE_OPTIONAL, 'Per-worker request buffer size (0 = unlimited)', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->ensureFrankenPhpIsInstalled($output);

        $host    = (string) $input->getOption('host');
        $port    = (int)    $input->getOption('port');
        $workers = max(1,   (int) $input->getOption('workers'));
        $buffer  = max(0,   (int) $input->getOption('buffer'));

        $projectDir = $this->kernel->getProjectDir();
        $stateDir   = $projectDir . '/var/trueasync';

        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }

        $workerPath    = $stateDir . '/worker.php';
        $caddyfilePath = $stateDir . '/Caddyfile';

        $this->writeWorkerFile($workerPath, $projectDir);
        $this->writeCaddyfile($caddyfilePath, $workerPath, $projectDir, $host, $port, $workers, $buffer);

        $output->writeln("Starting TrueAsync FrankenPHP on <info>{$host}:{$port}</info> ({$workers} worker(s), buffer={$buffer})");
        $output->writeln("  Worker:    {$workerPath}");
        $output->writeln("  Caddyfile: {$caddyfilePath}");
        $output->writeln('');

        $process = new Process(
            ['frankenphp', 'run', '--config', $caddyfilePath],
            $projectDir,
            array_merge($_ENV, $_SERVER, ['APP_ENV' => $this->kernel->getEnvironment()]),
        );

        $process->setTimeout(null);
        $process->start(fn ($type, $data) => $output->write($data));

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $process->stop(3));
        pcntl_signal(SIGINT,  fn () => $process->stop(3));

        return $process->wait() === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function ensureFrankenPhpIsInstalled(OutputInterface $output): void
    {
        exec('which frankenphp 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $output->writeln('<error>`frankenphp` binary not found in PATH.</error>');
            $output->writeln('Make sure you are inside the trueasync/php-true-async:latest-frankenphp container.');
            exit(1);
        }
    }

    private function writeWorkerFile(string $path, string $projectDir): void
    {
        $kernelClass = get_class($this->kernel);
        $env         = $this->kernel->getEnvironment();
        $debug       = $this->kernel->isDebug() ? 'true' : 'false';

        file_put_contents($path, <<<PHP
        <?php

        ini_set('display_errors', '1');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        require_once '{$projectDir}/vendor/autoload.php';

        \$kernel = new {$kernelClass}('{$env}', {$debug});
        \$kernel->boot();

        \$server = new \\Spawn\\Symfony\\Server\\FrankenPhpServer(\$kernel);
        \$server->start();
        PHP);
    }

    private function writeCaddyfile(
        string $path,
        string $workerPath,
        string $projectDir,
        string $host,
        int $port,
        int $workers,
        int $buffer,
    ): void {
        $bufferDirective = $buffer > 0 ? "\n                        buffer_size {$buffer}" : '';
        $logDir = $projectDir . '/var/log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($path, <<<CADDY
        {
            admin off
            frankenphp {
            }
        }

        :{$port} {
            bind {$host}
            root * {$projectDir}/public

            @static file
            handle @static {
                file_server
            }

            route {
                php_server {
                    index off
                    file_server off

                    worker {
                        file {$workerPath}
                        num {$workers}
                        async{$bufferDirective}
                        match *
                    }
                }
            }

            log {
                output file {$projectDir}/var/log/frankenphp-access.log
                level INFO
            }
        }
        CADDY);
    }
}
