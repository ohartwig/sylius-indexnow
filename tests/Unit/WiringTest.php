<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use GuzzleHttp\Psr7\HttpFactory;
use Koh\IndexNow\HostPolicy;
use Koh\IndexNow\Sylius\Http\TimeLimitedHttpClient;
use Koh\IndexNow\Sylius\KohIndexNowSyliusBundle;
use Koh\IndexNow\Sylius\Migrations\Version20260926120000;
use Koh\IndexNow\Sylius\Queue\Tables;
use Koh\IndexNow\Sylius\Schema\SchemaListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WiringTest extends TestCase
{
    #[Test]
    public function theDefaultsNeedNoConfigurationAtAll(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->getParameter('koh_indexnow.enabled'));
        self::assertSame('', $container->getParameter('koh_indexnow.key'));
        self::assertSame([], $container->getParameter('koh_indexnow.channels'));
        self::assertSame(
            ['product' => 'sylius_shop_product_show', 'taxon' => 'sylius_shop_product_index', 'homepage' => 'sylius_shop_homepage'],
            $container->getParameter('koh_indexnow.routes'),
        );
        self::assertTrue($container->hasDefinition(\Koh\IndexNow\Sylius\Command\RunCommand::class));
    }

    #[Test]
    public function configurationIsPassedThrough(): void
    {
        $container = $this->load(['channels' => ['DE'], 'denied_host_suffixes' => ['shop-staging.eu'], 'routes' => ['product' => 'app_product']]);

        self::assertSame(['DE'], $container->getParameter('koh_indexnow.channels'));
        self::assertSame(['shop-staging.eu'], $container->getParameter('koh_indexnow.denied_host_suffixes'));
        self::assertSame('app_product', ((array) $container->getParameter('koh_indexnow.routes'))['product'] ?? null);
    }

    #[Test]
    public function theHttpClientHasATimeoutAndFollowsNoRedirects(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('', ['http_code' => 202]);
        });
        $factory = new HttpFactory();
        $client = (TimeLimitedHttpClient::clientFactory(new TimeLimitedHttpClient($http, $factory, $factory), $factory))(HostPolicy::default());

        $result = $client->submit(\Koh\IndexNow\Submission::forHost('shop-domain.de', \Koh\IndexNow\Key::fromString('abcdef123456'), ['https://shop-domain.de/']));

        self::assertTrue($result->isSuccess());
        self::assertEquals(TimeLimitedHttpClient::TIMEOUT, $seen['timeout']);
        self::assertSame(0, $seen['max_redirects']);
    }

    #[Test]
    public function schemaToolingKnowsTheTablesAndTheMigrationCreatesAndDropsThem(): void
    {
        $schema = new Schema();
        (new SchemaListener())->postGenerateSchema(new GenerateSchemaEventArgs($this->createStub(EntityManagerInterface::class), $schema));
        self::assertTrue($schema->hasTable(Tables::QUEUE));
        self::assertTrue($schema->hasTable(Tables::HOST));

        $migration = new Version20260926120000(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new NullLogger());
        $fresh = new Schema();
        $migration->up($fresh);
        self::assertTrue($fresh->hasTable(Tables::QUEUE));
        $migration->down($fresh);
        self::assertFalse($fresh->hasTable(Tables::QUEUE));
        self::assertNotSame('', $migration->getDescription());
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'prod',
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.debug' => false,
        ]));
        $extension = (new KohIndexNowSyliusBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
