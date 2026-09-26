<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Koh\IndexNow\HostPolicy;
use Koh\IndexNow\IndexNowClient;
use Koh\IndexNow\Sylius\Activation\ChannelGate;
use Koh\IndexNow\Sylius\Processing\FullSubmission;
use Koh\IndexNow\Sylius\Processing\Processor;
use Koh\IndexNow\Sylius\Processing\RunReport;
use Koh\IndexNow\Sylius\Queue\QueueStore;
use Koh\IndexNow\Sylius\Queue\Reason;
use Koh\IndexNow\Sylius\Queue\Subject;
use Koh\IndexNow\Sylius\Queue\Tables;
use Koh\IndexNow\Sylius\Tests\Support\Shop;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

final class QueueAndProcessorTest extends TestCase
{
    private Connection $connection;

    private QueueStore $queue;

    private Shop $shop;

    /** @var list<RequestInterface> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        Tables::define($schema);
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->connection->executeStatement('CREATE TABLE sylius_product_channels (product_id INTEGER, channel_id INTEGER)');
        $this->connection->executeStatement('CREATE TABLE sylius_taxon (id INTEGER)');

        $this->queue = new QueueStore($this->connection);
        $this->shop = new Shop();
        $de = $this->shop->channel(1, 'DE', 'shop-domain.de', ['de_DE']);
        $ch = $this->shop->channel(2, 'CH', 'shop-domain.ch', ['de_DE']);
        $this->shop->product(10, ['de_DE' => 'zigarre'], [$de, $ch]);
        $this->shop->product(11, ['de_DE' => 'ausverkauft'], [$de], enabled: false);
        $this->connection->executeStatement('INSERT INTO sylius_product_channels VALUES (10, 1), (11, 1), (10, 2)');
    }

    #[Test]
    public function savingTheSameProductRepeatedlyQueuesItOnce(): void
    {
        self::assertSame(1, $this->queue->enqueue([Subject::product(10)], Reason::Change));
        self::assertSame(0, $this->queue->enqueue([Subject::product(10), Subject::product(10)], Reason::Change));
        self::assertCount(1, $this->queue->due(100));
    }

    #[Test]
    public function aChangeOutranksAQueuedGoLiveEntry(): void
    {
        $this->queue->enqueue([Subject::product(11)], Reason::Full);
        $this->queue->enqueue([Subject::product(11)], Reason::Change);

        self::assertSame(Reason::Change, $this->queue->due(10)[0]->reason);
    }

    #[Test]
    public function anAcceptedSubmissionEmptiesTheQueuePerHost(): void
    {
        $this->queue->enqueue([Subject::product(10)], Reason::Change);
        $report = $this->process([200]);

        self::assertSame(['shop-domain.de' => 1, 'shop-domain.ch' => 1], $report->submitted);
        self::assertSame([], $this->queue->due(100));
        self::assertCount(2, $this->requests);
    }

    #[Test]
    public function aProductOnTwoHostsWaitsWhileEitherHostAsksToRetry(): void
    {
        $this->queue->enqueue([Subject::product(10)], Reason::Change, 1000);
        $this->process([200, 429], 1000);

        $stats = $this->queue->statistics(1000);
        self::assertSame(1, $stats['total']);
        self::assertSame(1, $stats['retrying']);
        self::assertSame([], $this->queue->due(100, 1059), 'one minute of backoff after the first failure');
        self::assertCount(1, $this->queue->due(100, 1060));
    }

    #[Test]
    public function aRejectionIsLoggedAndNotRetried(): void
    {
        $this->queue->enqueue([Subject::product(11)], Reason::Change);
        $report = $this->process([422]);

        self::assertTrue($report->hasFailures());
        self::assertSame([], $this->queue->due(100));
    }

    #[Test]
    public function aNonProdShopSendsNothingAndKeepsNoQueue(): void
    {
        $this->queue->enqueue([Subject::product(10)], Reason::Change);
        $report = $this->process([200], null, $this->shop->gate('dev'));

        self::assertSame([], $this->requests);
        self::assertSame(1, $report->dropped);
        self::assertSame([], $this->queue->due(100));
    }

    #[Test]
    public function theGoLiveIsQueuedOncePerHostAndOnlyListsWhatIsEnabled(): void
    {
        $gate = $this->shop->gate();
        $full = new FullSubmission($this->connection, $this->queue, $this->shop->resolver($gate, false), true);

        // Entries are subjects, not URLs: product 10 and the homepage are
        // queued once for both hosts and resolved per host when sent.
        self::assertSame(['shop-domain.de' => 3, 'shop-domain.ch' => 0], $full->queuePending());
        self::assertSame([], $full->queuePending(), 'the marker survives: no second go-live');

        $this->process([200], null, $gate);
        $sent = [];
        foreach ($this->requests as $request) {
            $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertIsArray($payload['urlList']);
            $sent = [...$sent, ...$payload['urlList']];
        }
        sort($sent);
        self::assertSame([
            'https://shop-domain.ch/de_DE/',
            'https://shop-domain.ch/de_DE/products/zigarre',
            'https://shop-domain.de/de_DE/',
            'https://shop-domain.de/de_DE/products/zigarre',
        ], $sent, 'the disabled product is not part of the go-live');
    }

    /** @param list<int> $statuses */
    private function process(array $statuses, ?int $now = null, ?ChannelGate $gate = null): RunReport
    {
        $gate ??= $this->shop->gate();
        $http = new class ($statuses, $this->requests) implements ClientInterface {
            /**
             * @param list<int> $statuses
             * @param list<RequestInterface> $requests
             */
            public function __construct(private array $statuses, public array &$requests) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                $status = count($this->statuses) > 1 ? array_shift($this->statuses) : $this->statuses[0];

                return new Response($status);
            }
        };
        $factory = new HttpFactory();

        $report = new RunReport();
        (new Processor(
            $this->queue,
            $this->shop->resolver($gate, false),
            $gate,
            static fn(HostPolicy $policy): IndexNowClient => new IndexNowClient($http, $factory, $factory, null, $policy),
            new NullLogger(),
        ))->process($report, 100, $now);

        return $report;
    }
}
