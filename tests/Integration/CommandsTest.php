<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Integration;

use Doctrine\DBAL\Connection;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Koh\IndexNow\HostPolicy;
use Koh\IndexNow\IndexNowClient;
use Koh\IndexNow\Sylius\Activation\ChannelGate;
use Koh\IndexNow\Sylius\Command\RunCommand;
use Koh\IndexNow\Sylius\Command\StatusCommand;
use Koh\IndexNow\Sylius\Command\SubmitAllCommand;
use Koh\IndexNow\Sylius\Processing\FullSubmission;
use Koh\IndexNow\Sylius\Processing\Processor;
use Koh\IndexNow\Sylius\Processing\Runner;
use Koh\IndexNow\Sylius\Queue\QueueStore;
use Koh\IndexNow\Sylius\Tests\Support\Shop;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class CommandsTest extends TestCase
{
    private Connection $connection;

    private QueueStore $queue;

    private Shop $shop;

    private ChannelGate $gate;

    private int $status = 200;

    protected function setUp(): void
    {
        $this->connection = SqliteDatabase::withQueue();
        $this->queue = new QueueStore($this->connection);
        $this->shop = new Shop();
        $de = $this->shop->channel(1, 'DE', 'shop-domain.de', ['de_DE']);
        $this->shop->channel(2, 'DEV', 'shop.dev.shop-domain.de', ['de_DE']);
        $this->shop->product(10, ['de_DE' => 'zigarre'], [$de]);
        $this->connection->executeStatement('INSERT INTO sylius_product_channels VALUES (10, 1)');
        $this->gate = $this->shop->gate();
    }

    #[Test]
    public function theFirstRunAnnouncesTheShopAndTheSecondHasNothingLeft(): void
    {
        $tester = new CommandTester(new RunCommand($this->runner()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('shop-domain.de (2)', $tester->getDisplay());
        self::assertStringContainsString('shop-domain.de: 2 URLs announced', $tester->getDisplay());

        $tester->execute([]);
        self::assertStringContainsString('Go-live: none · Processed: 0', $tester->getDisplay());
    }

    #[Test]
    public function aRejectionMakesTheRunFailSoCronNotices(): void
    {
        $this->status = 422;
        $tester = new CommandTester(new RunCommand($this->runner()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('rejected', $tester->getDisplay());
    }

    #[Test]
    public function aThrottledRunIsNotAFailure(): void
    {
        $this->status = 429;
        $tester = new CommandTester(new RunCommand($this->runner()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('deferred', $tester->getDisplay());
    }

    #[Test]
    public function aSecondRunWaitsForTheFirst(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $held = $locks->createLock('koh_indexnow_run');
        $held->acquire();

        $tester = new CommandTester(new RunCommand($this->runner($locks)));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('in progress', $tester->getDisplay());
    }

    #[Test]
    public function statusExplainsEveryChannelHost(): void
    {
        $tester = new CommandTester(new StatusCommand($this->gate, $this->queue));
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('https://shop-domain.de/', $display);
        self::assertStringContainsString('pre-production host', $display);
        self::assertStringContainsString('Queue: 0 entries', $display);
    }

    #[Test]
    public function submitAllQueuesTheCatalogueOfTheGivenChannels(): void
    {
        $resolver = $this->shop->resolver($this->gate);
        $tester = new CommandTester(new SubmitAllCommand(new FullSubmission($this->connection, $this->queue, $resolver), $resolver));

        $tester->execute(['channel' => ['DE']]);
        self::assertStringContainsString('DE (shop-domain.de): 2 entries queued', $tester->getDisplay());

        $tester->execute(['channel' => ['XX']]);
        self::assertStringNotContainsString('entries queued', $tester->getDisplay());
    }

    private function runner(?LockFactory $locks = null): Runner
    {
        $factory = new HttpFactory();
        $test = $this;
        $http = new class ($test) implements ClientInterface {
            public function __construct(private readonly CommandsTest $test) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response($this->test->engineStatus());
            }
        };
        $resolver = $this->shop->resolver($this->gate, false);

        return new Runner(
            new FullSubmission($this->connection, $this->queue, $resolver),
            new Processor($this->queue, $resolver, $this->gate, static fn(HostPolicy $policy): IndexNowClient => new IndexNowClient($http, $factory, $factory, null, $policy), new NullLogger()),
            $this->gate,
            $locks ?? new LockFactory(new InMemoryStore()),
        );
    }

    public function engineStatus(): int
    {
        return $this->status;
    }
}
