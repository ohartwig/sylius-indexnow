<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Schema;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Koh\IndexNow\Sylius\Queue\Tables;

/** Makes the queue tables part of the schema Doctrine compares against. */
final class SchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        Tables::define($event->getSchema());
    }
}
