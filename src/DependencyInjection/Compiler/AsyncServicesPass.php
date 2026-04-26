<?php

namespace Spawn\Symfony\DependencyInjection\Compiler;

use Spawn\Symfony\Http\AsyncRequestStack;
use Spawn\Symfony\Routing\AsyncRequestContext;
use Spawn\Symfony\Security\AsyncTokenStorage;
use Spawn\Symfony\Translation\AsyncTranslator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replaces stateful Symfony services with async-safe per-coroutine versions.
 *
 * Two strategies depending on whether the original class is final:
 *   setClass()          — swaps the class, all bindings/tags preserved (RequestStack, RequestContext, TokenStorage)
 *   setDecoratedService() — wraps the original, used when the class is @final (Translator since 7.1)
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

        if ($container->hasDefinition('router.request_context')) {
            $container->getDefinition('router.request_context')
                ->setClass(AsyncRequestContext::class);
        }

        if ($container->hasDefinition('translator')) {
            $decorator = new Definition(AsyncTranslator::class, [
                new Reference('async.translator.inner'),
            ]);
            $decorator->setDecoratedService('translator');

            $container->setDefinition('async.translator', $decorator);
        }
    }
}
