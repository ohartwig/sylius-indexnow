<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Queue;

final readonly class QueueItem
{
    public function __construct(
        public int $id,
        public Subject $subject,
        public Reason $reason,
        public int $attempts,
    ) {}
}
