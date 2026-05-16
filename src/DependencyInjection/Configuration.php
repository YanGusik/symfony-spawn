<?php

declare(strict_types=1);

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
                ->arrayNode('server')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('listeners')
                            ->defaultValue([])
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('host')->defaultValue('0.0.0.0')->end()
                                    ->integerNode('port')->isRequired()->end()
                                    ->booleanNode('tls')->defaultFalse()->end()
                                    ->enumNode('protocol')
                                        ->values(['auto', 'http1', 'http2', 'http3'])
                                        ->defaultValue('auto')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('static_handlers')
                            ->defaultValue([])
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('prefix')->defaultValue('/static/')->end()
                                    ->scalarNode('root')->isRequired()->end()
                                    ->arrayNode('precompressed')
                                        ->scalarPrototype()->end()
                                        ->defaultValue([])
                                    ->end()
                                    ->booleanNode('etag')->defaultFalse()->end()
                                    ->arrayNode('open_file_cache')
                                        ->integerPrototype()->end()
                                        ->defaultValue([])
                                    ->end()
                                    ->enumNode('on_missing')
                                        ->values(['not_found', 'next'])
                                        ->defaultValue('not_found')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->scalarNode('tls_cert')->defaultNull()->end()
                        ->scalarNode('tls_key')->defaultNull()->end()
                        ->integerNode('backlog')->defaultValue(2048)->end()
                        ->booleanNode('compression')->defaultTrue()->end()
                        ->integerNode('max_body_size')->defaultValue(33554432)->end() // 32 MiB
                        ->integerNode('read_timeout')->defaultValue(60)->end()
                        ->integerNode('write_timeout')->defaultValue(60)->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
