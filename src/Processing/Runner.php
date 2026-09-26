<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Processing;

use Koh\IndexNow\Sylius\Activation\ChannelGate;
use Symfony\Component\Lock\LockFactory;

/** One run: queue the go-live where due, then send. Guarded against a parallel run. */
class Runner
{
    public function __construct(
        private readonly FullSubmission $fullSubmission,
        private readonly Processor $processor,
        private readonly ChannelGate $gate,
        private readonly ?LockFactory $locks = null,
    ) {}

    public function run(int $limit = 10000, ?int $now = null): ?RunReport
    {
        $lock = $this->locks?->createLock('koh_indexnow_run', 900);
        if ($lock !== null && !$lock->acquire()) {
            return null;
        }

        $this->gate->reset();
        try {
            $report = new RunReport();
            $report->fullSubmissions = $this->fullSubmission->queuePending($now);
            $this->processor->process($report, $limit, $now);

            return $report;
        } finally {
            $lock?->release();
        }
    }
}
