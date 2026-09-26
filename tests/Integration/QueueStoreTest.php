<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Integration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Koh\IndexNow\Sylius\Queue\QueueStore;
use Koh\IndexNow\Sylius\Queue\Reason;
use Koh\IndexNow\Sylius\Queue\Subject;
use Koh\IndexNow\Sylius\Queue\Tables;
use Koh\IndexNow\Url;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QueueStoreTest extends TestCase
{
    private QueueStore $queue;

    protected function setUp(): void
    {
        $this->queue = new QueueStore(SqliteDatabase::withQueue());
    }

    #[Test]
    public function aGoLiveEntryNeverDowngradesAQueuedChange(): void
    {
        $this->queue->enqueue([Subject::product(1)], Reason::Change);
        $this->queue->enqueue([Subject::product(1)], Reason::Full);

        self::assertSame(Reason::Change, $this->queue->due(10)[0]->reason);
    }

    #[Test]
    public function newEntriesAfterAnExistingOneInTheSameBatchAreStillAdded(): void
    {
        $this->queue->enqueue([Subject::product(1)], Reason::Change);

        self::assertSame(2, $this->queue->enqueue([Subject::product(1), Subject::product(2), Subject::taxon(3)], Reason::Change));
        self::assertCount(3, $this->queue->due(10));
    }

    #[Test]
    public function newEntriesAreDueImmediatelyAndRemovalIsPrecise(): void
    {
        $this->queue->enqueue([Subject::product(1), Subject::product(2)], Reason::Change, 5000);
        $items = $this->queue->due(10, 0);
        self::assertCount(2, $items, 'not_before starts at zero, not at the enqueue time');

        $this->queue->remove([$items[0]->id]);
        $left = $this->queue->due(10, 0);
        self::assertCount(1, $left);
        self::assertSame($items[1]->id, $left[0]->id);
        self::assertCount(1, $this->queue->due(1, 0), 'the limit is respected');
    }

    #[Test]
    public function statisticsCountAgainstTheGivenTime(): void
    {
        $this->queue->enqueue([Subject::product(1), Subject::product(2)], Reason::Change);
        $second = $this->queue->due(10)[1];
        $this->queue->defer($second->id, 2, 2000);

        self::assertSame(['total' => 2, 'due' => 1, 'retrying' => 1], $this->queue->statistics(1999));
        self::assertSame(['total' => 2, 'due' => 2, 'retrying' => 1], $this->queue->statistics(2000));
    }

    #[Test]
    public function theGoLiveMarkerIsPerHostAndCanBeRenewed(): void
    {
        self::assertFalse($this->queue->isFullySubmitted('shop-domain.de'));
        $this->queue->markFullySubmitted('shop-domain.de', 100);
        $this->queue->markFullySubmitted('shop-domain.de', 200);

        self::assertTrue($this->queue->isFullySubmitted('shop-domain.de'));
        self::assertFalse($this->queue->isFullySubmitted('shop-domain.ch'));
    }

    #[Test]
    public function subjectsOfDifferentKindsNeverShareADedupeKey(): void
    {
        $keys = [
            Subject::product(1)->key(),
            Subject::taxon(1)->key(),
            Subject::homepage()->key(),
            Subject::product(0)->key(),
            Subject::url('https://shop-domain.de/a')->key(),
            Subject::url('https://shop-domain.de/b')->key(),
            Subject::product(11)->key(),
        ];

        self::assertSame($keys, array_unique($keys));
        self::assertSame(Subject::product(1)->key(), Subject::product(1)->key());
        self::assertNull(Subject::fromRow('unknown', 1, ''));
        self::assertSame(0, Subject::homepage()->id);
        self::assertSame(0, Subject::url('https://shop-domain.de/')->id);
    }

    #[Test]
    public function theColumnsHoldWhatTheRestOfTheCodeProduces(): void
    {
        $schema = new Schema();
        Tables::define($schema);
        $queue = $schema->getTable(Tables::QUEUE);

        self::assertSame(Url::MAX_LENGTH, $queue->getColumn('url')->getLength(), 'every URL the library accepts fits');
        self::assertSame(40, $queue->getColumn('dedupe_key')->getLength(), 'a SHA-1 in hex');
        self::assertTrue($queue->getColumn('dedupe_key')->getFixed());
        self::assertTrue($queue->hasIndex('uniq_koh_indexnow_queue_dedupe') && $queue->getIndex('uniq_koh_indexnow_queue_dedupe')->isUnique());
        self::assertTrue($queue->hasIndex('idx_koh_indexnow_queue_due'));
        self::assertTrue($queue->getColumn('id')->getAutoincrement());
        self::assertSame(['id'], $queue->getPrimaryKey()?->getColumns());
        foreach (['attempts', 'not_before', 'created_at', 'subject_id'] as $column) {
            self::assertEquals(0, $queue->getColumn($column)->getDefault(), $column);
            self::assertTrue($queue->getColumn($column)->getUnsigned(), $column);
        }
        self::assertSame(16, $queue->getColumn('subject')->getLength());
        self::assertSame(16, $queue->getColumn('reason')->getLength());

        $host = $schema->getTable(Tables::HOST);
        self::assertSame(253, $host->getColumn('host')->getLength(), 'the longest DNS name');
        self::assertSame(['host'], $host->getPrimaryKey()?->getColumns());
        self::assertSame(Types::INTEGER, \Doctrine\DBAL\Types\Type::lookupName($host->getColumn('full_submitted_at')->getType()));

        Tables::define($schema);
        $this->addToAssertionCount(1);
    }
}
