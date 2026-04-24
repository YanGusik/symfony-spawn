<?php

use Spawn\Symfony\Console\ServeCommand;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ServeCommand::class)
        ->tag('console.command')
        ->autowire();
};
