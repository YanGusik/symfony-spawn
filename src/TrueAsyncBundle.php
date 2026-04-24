<?php

namespace Spawn\Symfony;

use Spawn\Symfony\DependencyInjection\Compiler\AsyncServicesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class TrueAsyncBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AsyncServicesPass());
    }
}
