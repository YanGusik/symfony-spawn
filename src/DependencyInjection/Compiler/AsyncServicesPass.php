<?php

namespace Spawn\Symfony\DependencyInjection\Compiler;

use Spawn\Symfony\Http\AsyncRequestStack;
use Spawn\Symfony\Security\AsyncTokenStorage;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Replaces stateful Symfony services with async-safe per-coroutine versions.
 *
 * Swaps the class on the existing service definition so all existing bindings,
 * aliases and tags are preserved — only the implementation changes.
 */
class AsyncServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('request_stack')) {
            $container->getDefinition('request_stack')
                ->setClass(AsyncRequestStack::class);
        }

        if ($container->hasDefinition('security.token_storage')) {
            $container->getDefinition('security.token_storage')
                ->setClass(AsyncTokenStorage::class);
        }
    }
}
