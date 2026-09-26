<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * IndexNow for Sylius: announces what changed in the shop to search engines.
 *
 * Nothing needs configuring for the common case. Channels are announced on
 * their hostname, the key is derived per host from kernel.secret, and only the
 * "prod" kernel environment ever sends anything.
 */
final class KohIndexNowSyliusBundle extends AbstractBundle
{
    protected string $extensionAlias = 'koh_indexnow';

    /** config/ sits at the package root, not under src/. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('key')
                    ->defaultValue('')
                    ->info('Empty: a key per host is derived from kernel.secret. Set only to keep a key registered elsewhere.')
                ->end()
                ->arrayNode('channels')
                    ->info('Channel codes to announce. Empty: every enabled channel with a hostname.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('denied_host_suffixes')
                    ->info('Hosts never announced, in addition to .localhost, .test, dev/staging/preview labels and the like.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('include_homepage')
                    ->defaultTrue()
                    ->info('Announce the homepage with the go-live submission.')
                ->end()
                ->booleanNode('include_main_taxon')
                    ->defaultTrue()
                    ->info('Announce a product\'s main taxon page with it: new and removed products change that listing.')
                ->end()
                ->arrayNode('routes')
                    ->addDefaultsIfNotSet()
                    ->info('Route names of the shop pages; change only if the shop replaced Sylius\' routes.')
                    ->children()
                        ->scalarNode('product')->defaultValue('sylius_shop_product_show')->end()
                        ->scalarNode('taxon')->defaultValue('sylius_shop_product_index')->end()
                        ->scalarNode('homepage')->defaultValue('sylius_shop_homepage')->end()
                    ->end()
                ->end()
            ->end();
    }

    /** @param array<mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $routes = \is_array($config['routes'] ?? null) ? $config['routes'] : [];

        $builder->setParameter('koh_indexnow.enabled', (bool) ($config['enabled'] ?? true));
        $builder->setParameter('koh_indexnow.key', \is_string($config['key'] ?? null) ? $config['key'] : '');
        $builder->setParameter('koh_indexnow.channels', self::strings($config['channels'] ?? []));
        $builder->setParameter('koh_indexnow.denied_host_suffixes', self::strings($config['denied_host_suffixes'] ?? []));
        $builder->setParameter('koh_indexnow.include_homepage', (bool) ($config['include_homepage'] ?? true));
        $builder->setParameter('koh_indexnow.include_main_taxon', (bool) ($config['include_main_taxon'] ?? true));
        $builder->setParameter('koh_indexnow.routes', [
            'product' => \is_string($routes['product'] ?? null) ? $routes['product'] : 'sylius_shop_product_show',
            'taxon' => \is_string($routes['taxon'] ?? null) ? $routes['taxon'] : 'sylius_shop_product_index',
            'homepage' => \is_string($routes['homepage'] ?? null) ? $routes['homepage'] : 'sylius_shop_homepage',
        ]);

        $container->import('../config/services.php');
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return \is_array($values) ? array_values(array_filter($values, \is_string(...))) : [];
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The two tables arrive with the application's regular
        // doctrine:migrations:migrate — no manual step at installation.
        if ($builder->hasExtension('doctrine_migrations')) {
            $builder->prependExtensionConfig('doctrine_migrations', [
                'migrations_paths' => ['Koh\\IndexNow\\Sylius\\Migrations' => __DIR__ . '/Migrations'],
            ]);
        }
    }
}
