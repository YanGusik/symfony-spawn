<?php

namespace Spawn\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('true_async');
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->arrayNode('db_pool')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('min')->defaultValue(2)->end()
                        ->integerNode('max')->defaultValue(10)->end()
                        ->integerNode('healthcheck_interval')->defaultValue(30)->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
