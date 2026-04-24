<?php

namespace Spawn\Symfony\Console;

use Spawn\Symfony\Server\DevServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AsCommand(name: 'async:serve', description: 'Start the TrueAsync development server')]
class ServeCommand extends Command
{
    public function __construct(private readonly HttpKernelInterface $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Bind address', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Bind port', '8080');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int)    $input->getOption('port');

        $output->writeln("Starting TrueAsync server on <info>tcp://{$host}:{$port}</info>");

        $server = new DevServer($this->kernel, $host, $port);
        $server->prepareApp();
        $server->start();

        return Command::SUCCESS;
    }
}
