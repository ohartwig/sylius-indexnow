<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Command;

use Koh\IndexNow\Sylius\Processing\FullSubmission;
use Koh\IndexNow\Sylius\Url\UrlResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Queue the whole catalogue of announcing channels again — after a relaunch or
 * a domain move. The first announcement happens by itself.
 */
#[AsCommand('koh:indexnow:submit-all', 'IndexNow: queue every product, taxon and homepage of the announcing channels.')]
final class SubmitAllCommand extends Command
{
    public function __construct(
        private readonly FullSubmission $fullSubmission,
        private readonly UrlResolver $urls,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('channel', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Channel codes; all announcing channels when omitted.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $codes = array_filter((array) $input->getArgument('channel'), is_string(...));

        foreach ($this->urls->announcingChannels() as $host => $channel) {
            if ($codes !== [] && !in_array($channel->getCode(), $codes, true)) {
                continue;
            }
            $io->writeln(sprintf('%s (%s): %d entries queued', (string) $channel->getCode(), $host, $this->fullSubmission->queueChannel($channel)));
        }
        $io->note('Queued only. The next koh:indexnow:run sends them (see koh:indexnow:status).');

        return Command::SUCCESS;
    }
}
