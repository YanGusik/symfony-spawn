<?php

use Spawn\Symfony\Console\FrankenServeCommand;
use Spawn\Symfony\Console\ServeCommand;
use Spawn\Symfony\EventListener\EntityManagerResetListener;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ServeCommand::class)
        ->tag('console.command')
        ->autowire();

    $services->set(FrankenServeCommand::class)
        ->tag('console.command')
        ->autowire();

    if (interface_exists(ManagerRegistry::class)) {
        $services->set(EntityManagerResetListener::class)
            ->autowire()
            ->autoconfigure();
    }
};
