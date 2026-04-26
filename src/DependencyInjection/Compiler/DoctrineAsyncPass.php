<?php

namespace Spawn\Symfony\DependencyInjection\Compiler;

use Spawn\Symfony\Database\AsyncConnection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Configures Doctrine DBAL connections for async execution:
 *
 *  1. Replaces the Connection class with AsyncConnection for per-coroutine
 *     transaction nesting isolation (same effect as doctrine wrapperClass).
 *
 *  2. Injects PDO pool attributes into driverOptions of every connection so
 *     TrueAsync's C-level pool is enabled with configured limits.
 *     The pool is shared within a worker — one pool per worker, not per request.
 */
class DoctrineAsyncPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('doctrine.connections')) {
            return;
        }

        $poolConfig = $container->getParameter('true_async.db_pool');

        foreach (array_keys($container->getParameter('doctrine.connections')) as $name) {
            $connectionId = sprintf('doctrine.dbal.%s_connection', $name);

            if (!$container->hasDefinition($connectionId)) {
                continue;
            }

            $def = $container->getDefinition($connectionId);

            // Swap class — same as setting wrapperClass in doctrine.yaml
            $def->setClass(AsyncConnection::class);

            if (!($poolConfig['enabled'] ?? false)) {
                continue;
            }

            // Inject PDO Pool attributes into connection params (driverOptions).
            // These are passed as the 4th argument to the PDO constructor at
            // connection time, enabling TrueAsync's C-level connection pooling.
            $args   = $def->getArguments();
            $params = $args[0] ?? [];

            // Use + not array_merge: array_merge reindexes integer keys (PDO constants),
            // turning [22=>true, 23=>2, ...] into [0=>true, 1=>2, ...].
            $params['driverOptions'] = [
                \PDO::ATTR_POOL_ENABLED              => true,
                \PDO::ATTR_POOL_MIN                  => $poolConfig['min'],
                \PDO::ATTR_POOL_MAX                  => $poolConfig['max'],
                \PDO::ATTR_POOL_HEALTHCHECK_INTERVAL => $poolConfig['healthcheck_interval'],
            ] + ($params['driverOptions'] ?? []);

            $def->setArgument(0, $params);
        }
    }
}
