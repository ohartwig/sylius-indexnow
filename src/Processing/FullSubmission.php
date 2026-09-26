<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Processing;

use Doctrine\DBAL\Connection;
use Koh\IndexNow\Sylius\Queue\QueueStore;
use Koh\IndexNow\Sylius\Queue\Reason;
use Koh\IndexNow\Sylius\Queue\Subject;
use Koh\IndexNow\Sylius\Url\UrlResolver;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * Announces a shop once, the first time a channel host is active: the go-live.
 *
 * Queues every product assigned to the channel, every taxon and the
 * homepages; the strict resolver rules decide at send time what is enabled
 * and visible. The marker is a database row, so a redeploy, a new pod or a
 * cleared cache does not announce the whole catalogue again.
 */
class FullSubmission
{
    public function __construct(
        private readonly Connection $connection,
        private readonly QueueStore $queue,
        private readonly UrlResolver $urls,
        private readonly bool $includeHomepage = true,
    ) {}

    /** @return array<string, int> host => subjects queued */
    public function queuePending(?int $now = null): array
    {
        $queued = [];
        foreach ($this->urls->announcingChannels() as $host => $channel) {
            if ($this->queue->isFullySubmitted($host)) {
                continue;
            }
            $queued[$host] = $this->queueChannel($channel, $now);
            $this->queue->markFullySubmitted($host, $now);
        }

        return $queued;
    }

    public function queueChannel(ChannelInterface $channel, ?int $now = null): int
    {
        $subjects = $this->includeHomepage ? [Subject::homepage()] : [];

        // Plain ids through DBAL: loading a catalogue of entities to learn
        // their ids would cost more than the whole announcement.
        foreach ($this->connection->fetchFirstColumn(
            'SELECT product_id FROM sylius_product_channels WHERE channel_id = :channel',
            ['channel' => $channel->getId()],
        ) as $id) {
            if (is_numeric($id)) {
                $subjects[] = Subject::product((int) $id);
            }
        }
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM sylius_taxon') as $id) {
            if (is_numeric($id)) {
                $subjects[] = Subject::taxon((int) $id);
            }
        }

        return $this->queue->enqueue($subjects, Reason::Full, $now);
    }
}
