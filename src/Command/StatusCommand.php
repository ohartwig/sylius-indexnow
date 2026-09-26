<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Command;

use Koh\IndexNow\Sylius\Activation\ChannelGate;
use Koh\IndexNow\Sylius\Queue\QueueStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Answers "why is nothing announced?" per channel host, and shows the queue. */
#[AsCommand('koh:indexnow:status', 'IndexNow: show per channel host whether it announces, why not, and the key file URL.')]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly ChannelGate $gate,
        private readonly QueueStore $queue,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach ($this->gate->channelsByHost() as $host => $channel) {
            $activation = $this->gate->forHost($host);
            $rows[] = [
                (string) $channel->getCode(),
                $host,
                $activation->active ? '<info>active</info>' : '<comment>inactive</comment>',
                $activation->active && $activation->key !== null ? 'https://' . $host . '/' . $activation->key->fileName() : $activation->reason,
                $this->queue->isFullySubmitted($host) ? 'yes' : 'no',
            ];
        }
        $io->table(['Channel', 'Host', 'State', 'Key file / reason', 'Go-live sent'], $rows);

        $statistics = $this->queue->statistics();
        $io->writeln(sprintf('Queue: %d entries, %d due, %d waiting after a failed attempt.', $statistics['total'], $statistics['due'], $statistics['retrying']));

        return Command::SUCCESS;
    }
}
