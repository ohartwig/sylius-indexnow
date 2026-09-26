<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Koh\IndexNow\Sylius\Queue\Tables;

final class SqliteDatabase
{
    public static function withQueue(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        Tables::define($schema);
        foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
            $connection->executeStatement($sql);
        }
        $connection->executeStatement('CREATE TABLE sylius_product_channels (product_id INTEGER, channel_id INTEGER)');
        $connection->executeStatement('CREATE TABLE sylius_taxon (id INTEGER)');

        return $connection;
    }
}
