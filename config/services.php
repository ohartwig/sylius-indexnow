<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\ToolEvents;
use GuzzleHttp\Psr7\HttpFactory;
use Koh\IndexNow\Sylius\Activation\ChannelGate;
use Koh\IndexNow\Sylius\Change\DoctrineChangeListener;
use Koh\IndexNow\Sylius\Command\RunCommand;
use Koh\IndexNow\Sylius\Command\StatusCommand;
use Koh\IndexNow\Sylius\Command\SubmitAllCommand;
use Koh\IndexNow\Sylius\Http\KeyFileController;
use Koh\IndexNow\Sylius\Http\TimeLimitedHttpClient;
use Koh\IndexNow\Sylius\Processing\FullSubmission;
use Koh\IndexNow\Sylius\Processing\Processor;
use Koh\IndexNow\Sylius\Processing\Runner;
use Koh\IndexNow\Sylius\Queue\QueueStore;
use Koh\IndexNow\Sylius\Schema\SchemaListener;
use Koh\IndexNow\Sylius\Url\UrlResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ChannelGate::class)
        ->args([
            service('sylius.repository.channel'),
            param('kernel.environment'),
            param('koh_indexnow.enabled'),
            param('koh_indexnow.key'),
            param('kernel.secret'),
            param('koh_indexnow.channels'),
            param('koh_indexnow.denied_host_suffixes'),
        ])
        // Caches activations per host; a long-lived kernel (FrankenPHP worker
        // mode) would otherwise keep a channel's old state until it restarts.
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(QueueStore::class)
        ->args([service('doctrine.dbal.default_connection')]);

    $services->set(UrlResolver::class)
        ->args([
            service(ChannelGate::class),
            service('router'),
            service('sylius.repository.product'),
            service('sylius.repository.taxon'),
            param('koh_indexnow.routes'),
            param('koh_indexnow.include_main_taxon'),
        ]);

    $services->set('koh_indexnow.psr17_factory', HttpFactory::class);

    $services->set(TimeLimitedHttpClient::class)
        ->args([service('http_client'), service('koh_indexnow.psr17_factory'), service('koh_indexnow.psr17_factory')]);

    $services->set('koh_indexnow.client_factory', \Closure::class)
        ->factory([TimeLimitedHttpClient::class, 'clientFactory'])
        ->args([service(TimeLimitedHttpClient::class), service('koh_indexnow.psr17_factory')]);

    $services->set(Processor::class)
        ->args([
            service(QueueStore::class),
            service(UrlResolver::class),
            service(ChannelGate::class),
            service('koh_indexnow.client_factory'),
            service('logger')->nullOnInvalid(),
        ]);

    $services->set(FullSubmission::class)
        ->args([
            service('doctrine.dbal.default_connection'),
            service(QueueStore::class),
            service(UrlResolver::class),
            param('koh_indexnow.include_homepage'),
        ]);

    $services->set(Runner::class)
        ->args([
            service(FullSubmission::class),
            service(Processor::class),
            service(ChannelGate::class),
            service('lock.factory')->nullOnInvalid(),
        ]);

    $services->set(DoctrineChangeListener::class)
        ->args([service(QueueStore::class), service(UrlResolver::class), service('logger')->nullOnInvalid()])
        ->tag('doctrine.event_listener', ['event' => Events::postPersist])
        ->tag('doctrine.event_listener', ['event' => Events::preUpdate])
        ->tag('doctrine.event_listener', ['event' => Events::postUpdate])
        ->tag('doctrine.event_listener', ['event' => Events::preRemove])
        ->tag('doctrine.event_listener', ['event' => Events::postFlush])
        ->tag('doctrine.event_listener', ['event' => Events::onClear])
        // The buffers hold entity references and are emptied on postFlush and
        // onClear only. After a failed flush the entity manager is replaced
        // without a clear(), so in a long-lived kernel (FrankenPHP worker mode)
        // they would carry the failed request's entities into the next one.
        ->tag('kernel.reset', ['method' => 'onClear']);

    $services->set(SchemaListener::class)
        ->tag('doctrine.event_listener', ['event' => ToolEvents::postGenerateSchema]);

    $services->set(KeyFileController::class)
        ->args([service(ChannelGate::class)])
        ->public()
        ->tag('controller.service_arguments');

    foreach ([RunCommand::class => [service(Runner::class)], StatusCommand::class => [service(ChannelGate::class), service(QueueStore::class)], SubmitAllCommand::class => [service(FullSubmission::class), service(UrlResolver::class)]] as $command => $arguments) {
        $services->set($command)->args($arguments)->tag('console.command');
    }
};
