<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Queue;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * The two tables, defined once for the migration and for schema tooling.
 *
 * Declared to Doctrine's schema tool too (see SchemaListener), so
 * doctrine:migrations:diff in the application neither proposes to create
 * them a second time nor to drop them as unknown.
 */
final class Tables
{
    public const QUEUE = 'koh_indexnow_queue';
    public const HOST = 'koh_indexnow_host';

    public static function define(Schema $schema): void
    {
        if (!$schema->hasTable(self::QUEUE)) {
            $queue = $schema->createTable(self::QUEUE);
            $queue->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => true]);
            $queue->addColumn('subject', Types::STRING, ['length' => 16]);
            $queue->addColumn('subject_id', Types::INTEGER, ['unsigned' => true, 'default' => 0]);
            $queue->addColumn('url', Types::STRING, ['length' => 2048, 'default' => '']);
            $queue->addColumn('dedupe_key', Types::STRING, ['length' => 40, 'fixed' => true]);
            $queue->addColumn('reason', Types::STRING, ['length' => 16]);
            $queue->addColumn('attempts', Types::INTEGER, ['unsigned' => true, 'default' => 0]);
            $queue->addColumn('not_before', Types::INTEGER, ['unsigned' => true, 'default' => 0]);
            $queue->addColumn('created_at', Types::INTEGER, ['unsigned' => true, 'default' => 0]);
            $queue->setPrimaryKey(['id']);
            $queue->addUniqueIndex(['dedupe_key'], 'uniq_koh_indexnow_queue_dedupe');
            $queue->addIndex(['not_before'], 'idx_koh_indexnow_queue_due');
        }

        if (!$schema->hasTable(self::HOST)) {
            $host = $schema->createTable(self::HOST);
            $host->addColumn('host', Types::STRING, ['length' => 253]);
            $host->addColumn('full_submitted_at', Types::INTEGER, ['unsigned' => true, 'default' => 0]);
            $host->setPrimaryKey(['host']);
        }
    }
}
