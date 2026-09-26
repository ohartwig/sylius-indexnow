<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Processing;

final class RunReport
{
    /** @var array<string, int> host => subjects queued for the go-live submission */
    public array $fullSubmissions = [];

    public int $processed = 0;

    public int $dropped = 0;

    /** @var array<string, int> host => URLs accepted */
    public array $submitted = [];

    /** @var array<string, string> */
    public array $deferred = [];

    /** @var array<string, string> */
    public array $failed = [];

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }
}
