<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Koh\IndexNow\Sylius\Queue\Tables;

final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'koh/sylius-indexnow: queue and per-host state tables.';
    }

    public function up(Schema $schema): void
    {
        Tables::define($schema);
    }

    public function down(Schema $schema): void
    {
        foreach ([Tables::QUEUE, Tables::HOST] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
}
