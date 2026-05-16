<?php

declare(strict_types=1);

namespace Spawn\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class TrueAsyncExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $container->setParameter('true_async.db_pool', $config['db_pool']);
        $container->setParameter('true_async.server', $config['server']);

        $loader = new PhpFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/config'));
        $loader->load('services.php');
    }

    public function getAlias(): string
    {
        return 'true_async';
    }
}
