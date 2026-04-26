<?php

namespace Spawn\Symfony;

use Spawn\Symfony\DependencyInjection\Compiler\AsyncServicesPass;
use Spawn\Symfony\DependencyInjection\Compiler\DoctrineAsyncPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class TrueAsyncBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AsyncServicesPass());

        // DoctrineAsyncPass runs after DoctrineBundle's MiddlewaresPass so our
        // class swap isn't overwritten. Priority < 0 means "late" in the pipeline.
        $container->addCompilerPass(new DoctrineAsyncPass(), priority: -10);
    }
}
