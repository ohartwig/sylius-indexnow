<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Command;

use Koh\IndexNow\Sylius\Processing\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run every few minutes from cron. Exit code 1 only for rejections that will
 * not heal; throttling and outages are retried by the queue.
 */
#[AsCommand('koh:indexnow:run', 'IndexNow: announce changed and newly launched products, taxons and homepages.')]
final class RunCommand extends Command
{
    public function __construct(private readonly Runner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum queued entries to process in this run.', '10000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $input->getOption('limit');
        $report = $this->runner->run(is_numeric($limit) ? max(1, (int) $limit) : 10000);
        if ($report === null) {
            $io->note('Another IndexNow run is in progress; nothing done.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Go-live: %s · Processed: %d · Dropped: %d',
            $report->fullSubmissions === [] ? 'none' : implode(', ', array_map(static fn(string $host, int $count): string => $host . ' (' . $count . ')', array_keys($report->fullSubmissions), $report->fullSubmissions)),
            $report->processed,
            $report->dropped,
        ));
        foreach ($report->submitted as $host => $count) {
            $io->writeln(sprintf('  <info>%s</info>: %d URLs announced', $host, $count));
        }
        foreach ($report->deferred as $host => $reason) {
            $io->writeln(sprintf('  <comment>%s</comment>: deferred — %s', $host, $reason));
        }
        foreach ($report->failed as $host => $reason) {
            $io->writeln(sprintf('  <error>%s</error>: rejected — %s', $host, $reason));
        }

        return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }
}
