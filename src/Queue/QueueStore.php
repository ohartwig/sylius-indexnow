<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Queue;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/**
 * The queue and the per-host go-live marker, on plain DBAL.
 *
 * Enqueueing runs inside an admin's flush, so it is one SELECT and a handful
 * of INSERTs and nothing else. The unique dedupe key makes a product saved
 * twenty times before the next run a single row.
 */
class QueueStore
{
    private const CHUNK = 500;

    public function __construct(private readonly Connection $connection) {}

    /**
     * @param iterable<Subject> $subjects
     *
     * @return int rows added
     */
    public function enqueue(iterable $subjects, Reason $reason, ?int $now = null): int
    {
        $now ??= time();
        $unique = [];
        foreach ($subjects as $subject) {
            $unique[$subject->key()] = $subject;
        }

        $added = 0;
        foreach (array_chunk($unique, self::CHUNK, true) as $chunk) {
            $existing = $this->existingReasons(array_keys($chunk));
            foreach ($chunk as $key => $subject) {
                if (isset($existing[$key])) {
                    // A change outranks a queued go-live entry, whose strict
                    // rules would drop a product that was just disabled.
                    if (!$reason->isStrict() && $existing[$key] === Reason::Full->value) {
                        $this->connection->update(Tables::QUEUE, ['reason' => $reason->value], ['dedupe_key' => $key]);
                    }
                    continue;
                }
                try {
                    $added += (int) $this->connection->insert(Tables::QUEUE, [
                        'subject' => $subject->type,
                        'subject_id' => $subject->id,
                        'url' => $subject->url,
                        'dedupe_key' => $key,
                        'reason' => $reason->value,
                        'attempts' => 0,
                        'not_before' => 0,
                        'created_at' => $now,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Queued by a concurrent flush in the meantime; same effect.
                }
            }
        }

        return $added;
    }

    /**
     * @return list<QueueItem>
     *
     * @phpstan-impure reads a table that changes between calls
     */
    public function due(int $limit, ?int $now = null): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'subject', 'subject_id', 'url', 'reason', 'attempts')
            ->from(Tables::QUEUE)
            ->where('not_before <= :now')
            ->setParameter('now', $now ?? time(), ParameterType::INTEGER)
            ->orderBy('id')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        $items = [];
        foreach ($rows as $row) {
            $subject = Subject::fromRow(self::string($row, 'subject'), self::int($row, 'subject_id'), self::string($row, 'url'));
            if ($subject === null) {
                $this->remove([self::int($row, 'id')]);
                continue;
            }
            $items[] = new QueueItem(
                self::int($row, 'id'),
                $subject,
                Reason::tryFrom(self::string($row, 'reason')) ?? Reason::Change,
                self::int($row, 'attempts'),
            );
        }

        return $items;
    }

    /** @param list<int> $ids */
    public function remove(array $ids): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->connection->executeStatement(
                'DELETE FROM ' . Tables::QUEUE . ' WHERE id IN (:ids)',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::INTEGER],
            );
        }
    }

    public function defer(int $id, int $attempts, int $notBefore): void
    {
        $this->connection->update(Tables::QUEUE, ['attempts' => $attempts, 'not_before' => $notBefore], ['id' => $id]);
    }

    /**
     * @return array{total: int, due: int, retrying: int}
     *
     * @phpstan-impure
     */
    public function statistics(?int $now = null): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total,'
            . ' SUM(CASE WHEN not_before <= :now THEN 1 ELSE 0 END) AS due,'
            . ' SUM(CASE WHEN attempts > 0 THEN 1 ELSE 0 END) AS retrying'
            . ' FROM ' . Tables::QUEUE,
            ['now' => $now ?? time()],
        ) ?: [];

        return ['total' => self::int($row, 'total'), 'due' => self::int($row, 'due'), 'retrying' => self::int($row, 'retrying')];
    }

    public function isFullySubmitted(string $host): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM ' . Tables::HOST . ' WHERE host = :host', ['host' => $host]) !== false;
    }

    public function markFullySubmitted(string $host, ?int $now = null): void
    {
        try {
            $this->connection->insert(Tables::HOST, ['host' => $host, 'full_submitted_at' => $now ?? time()]);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update(Tables::HOST, ['full_submitted_at' => $now ?? time()], ['host' => $host]);
        }
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string> dedupe key => queued reason
     */
    private function existingReasons(array $keys): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT dedupe_key, reason FROM ' . Tables::QUEUE . ' WHERE dedupe_key IN (:keys)',
            ['keys' => $keys],
            ['keys' => ArrayParameterType::STRING],
        );

        $existing = [];
        foreach ($rows as $row) {
            $existing[self::string($row, 'dedupe_key')] = self::string($row, 'reason');
        }

        return $existing;
    }

    /** @param array<array-key, mixed> $row */
    private static function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    /** @param array<array-key, mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
