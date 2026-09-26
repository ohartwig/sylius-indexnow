<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Queue;

/**
 * A change is announced even when the product is now disabled — the engine
 * has to re-crawl to learn that. The go-live submission lists only what is
 * meant to be found.
 */
enum Reason: string
{
    case Change = 'change';
    case Full = 'full';

    public function isStrict(): bool
    {
        return $this === self::Full;
    }
}
