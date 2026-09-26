<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Processing;

use Koh\IndexNow\Exception\HostNotAllowed;
use Koh\IndexNow\Exception\InvalidUrl;
use Koh\IndexNow\IndexNowClient;
use Koh\IndexNow\Result;
use Koh\IndexNow\Submission;
use Koh\IndexNow\UrlCollection;
use Koh\IndexNow\Sylius\Activation\ChannelGate;
use Koh\IndexNow\Sylius\Queue\QueueItem;
use Koh\IndexNow\Sylius\Queue\QueueStore;
use Koh\IndexNow\Sylius\Url\UrlResolver;
use Psr\Log\LoggerInterface;

/**
 * Sends what is due, one request per host, and settles each queue row.
 *
 * A product can live on several channels and so on several hosts; its row is
 * removed only when every host it maps to has either accepted it or rejected
 * it for good, and waits with backoff while any of them asks to retry.
 */
class Processor
{
    public const MAX_ATTEMPTS = 8;

    private const BASE_BACKOFF = 60;
    private const MAX_BACKOFF = 6 * 3600;

    /** @param \Closure(\Koh\IndexNow\HostPolicy): IndexNowClient $clientFactory */
    public function __construct(
        private readonly QueueStore $queue,
        private readonly UrlResolver $resolver,
        private readonly ChannelGate $gate,
        private readonly \Closure $clientFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(RunReport $report, int $limit = 10000, ?int $now = null): void
    {
        $now ??= time();
        $items = $this->queue->due($limit, $now);
        $report->processed += count($items);

        /** @var array<string, array<string, true>> $urlsByHost */
        $urlsByHost = [];
        /** @var array<int, list<string>> $hostsByItem */
        $hostsByItem = [];
        $drop = [];
        foreach ($items as $item) {
            $resolved = UrlCollection::of($this->resolver->resolve($item->subject, $item->reason->isStrict()));
            foreach ($resolved->rejected() as $url => $reason) {
                $this->logger->warning('IndexNow: not announcing {url}: {reason}', ['url' => $url, 'reason' => $reason]);
            }
            foreach ($resolved->hosts() as $host) {
                if (!$this->gate->forHost($host)->active) {
                    continue;
                }
                $hostsByItem[$item->id][] = $host;
                foreach ($resolved->urlsFor($host) as $url) {
                    $urlsByHost[$host][$url] = true;
                }
            }
            if (!isset($hostsByItem[$item->id])) {
                $drop[] = $item->id;
            }
        }
        $this->queue->remove($drop);
        $report->dropped += count($drop);

        /** @var array<string, Result|null> $outcomeByHost null: accepted */
        $outcomeByHost = [];
        foreach ($urlsByHost as $host => $urls) {
            $outcomeByHost[$host] = $this->submit($report, $host, array_keys($urls));
        }

        $this->settle($report, $items, $hostsByItem, $outcomeByHost, $now);
    }

    /** @param list<string> $urls */
    private function submit(RunReport $report, string $host, array $urls): ?Result
    {
        $activation = $this->gate->forHost($host);
        if ($activation->key === null || $activation->hostPolicy === null) {
            return null;
        }

        try {
            $submission = Submission::forHost($host, $activation->key, $urls);
            $results = ($this->clientFactory)($activation->hostPolicy)->submitAll($submission);
        } catch (HostNotAllowed | InvalidUrl $e) {
            $report->failed[$host] = $e->getMessage();
            $this->logger->error('IndexNow: {host} refused before sending: {message}', ['host' => $host, 'message' => $e->getMessage()]);

            return new Result(\Koh\IndexNow\Outcome::Rejected, $host, count($urls), null, null, $e->getMessage());
        }

        $last = $results === [] ? null : $results[array_key_last($results)];
        if ($last === null || ($last->isSuccess() && count($results) === count($submission->chunks()))) {
            $report->submitted[$host] = ($report->submitted[$host] ?? 0) + count($urls);
            $this->logger->info('IndexNow: announced {count} URLs for {host}', ['count' => count($urls), 'host' => $host]);

            return null;
        }

        $detail = self::describe($last);
        if ($last->isRetryable()) {
            $report->deferred[$host] = $detail;
            $this->logger->warning('IndexNow: {host} deferred: {detail}', ['host' => $host, 'detail' => $detail]);
        } else {
            $report->failed[$host] = $detail;
            $this->logger->error('IndexNow: {host} rejected {count} URLs, not retrying: {detail}', ['host' => $host, 'count' => count($urls), 'detail' => $detail]);
        }

        return $last;
    }

    /**
     * @param list<QueueItem> $items
     * @param array<int, list<string>> $hostsByItem
     * @param array<string, Result|null> $outcomeByHost
     */
    private function settle(RunReport $report, array $items, array $hostsByItem, array $outcomeByHost, int $now): void
    {
        $done = [];
        foreach ($items as $item) {
            if (!isset($hostsByItem[$item->id])) {
                continue;
            }
            $retry = null;
            foreach ($hostsByItem[$item->id] as $host) {
                $outcome = $outcomeByHost[$host] ?? null;
                if ($outcome !== null && $outcome->isRetryable()) {
                    $retry = $outcome;
                }
            }
            if ($retry === null) {
                $done[] = $item->id;
                continue;
            }

            $attempts = $item->attempts + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $done[] = $item->id;
                $this->logger->error('IndexNow: {subject} given up after {max} attempts', ['subject' => $item->subject->type . ':' . $item->subject->id, 'max' => self::MAX_ATTEMPTS]);
                continue;
            }
            $backoff = min(self::MAX_BACKOFF, self::BASE_BACKOFF << min($item->attempts, 16));
            $this->queue->defer($item->id, $attempts, $now + max($backoff, $retry->retryAfterSeconds ?? 0));
        }
        $this->queue->remove($done);
    }

    private static function describe(Result $result): string
    {
        return trim(sprintf('%s%s %s', $result->outcome->value, $result->statusCode !== null ? ' (HTTP ' . $result->statusCode . ')' : '', $result->detail));
    }
}
