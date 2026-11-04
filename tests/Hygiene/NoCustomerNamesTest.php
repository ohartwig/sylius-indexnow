<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Tests\Hygiene;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Keeps customer and organisation names out of this repository.
 *
 * The repository is published to a public mirror. Test fixtures once carried the
 * names of real installations and organisations, and everything in the tree
 * becomes public with the mirror (AUD-053-F01). Use neutral placeholders such as
 * "Example Shop" or `example` instead.
 *
 * The names are listed as SHA-256 hashes of the lowercased word, because a plain
 * denylist would put the very word it guards against back into the tree. To add
 * one: `printf '%s' name | shasum -a 256`.
 *
 * A test rather than a separate lint job because the unit suite is already
 * blocking in this repository's pipeline, needs no image of its own, and fails
 * in the same place a developer looks first.
 */
#[CoversNothing]
final class NoCustomerNamesTest extends TestCase
{
    /** @var list<string> */
    private const array FORBIDDEN_WORD_HASHES = [
        '8ec48b4bfe7adb56f00fdc90760e11ff982ed0955f58f8ef3af170a1c4cb35af',
        '507f43168eacc4929060ac361ea604fdaab66b1d8d07de4473201d485e4adcdc',
        'edd6fd530a500ec43321c3ec9d6897680275aeb7bae020a62f1ca5c363e824a1',
        '570ffd808f50eab736330b9692d0ae1fbb2c6a2c1c6a4364d0105591eaa957cf',
        '4c813331d2c1a806a81f7cbf599e686d0e1a68a2fb92d989e6e956c2e1cac490',
    ];

    /** Paths below the repository root that are not the repository's own text. */
    private const array SKIPPED = ['.git', 'vendor', '.phpunit.cache', 'var', 'node_modules', '.cache', 'build', 'coverage', '.Build'];

    public function testNoFileNamesACustomer(): void
    {
        $forbidden = array_flip(self::FORBIDDEN_WORD_HASHES);
        $offenders = [];

        foreach ($this->files(\dirname(__DIR__, 2)) as $relative => $contents) {
            preg_match_all('/\p{L}{3,}/u', $contents, $words);

            foreach (array_unique($words[0]) as $word) {
                if (isset($forbidden[hash('sha256', mb_strtolower($word))])) {
                    $offenders[] = $relative;

                    break;
                }
            }
        }

        self::assertSame([], $offenders, 'These files name a customer or organisation. Use a neutral placeholder such as "Example Shop".');
    }

    /**
     * Every text file of the repository, keyed by its path relative to the root.
     *
     * @return iterable<string, string>
     */
    private function files(string $root): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !($file->isDir() && \in_array($file->getFilename(), self::SKIPPED, true)),
            ),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // Binary files and anything unreadable have no words to check.
            if (false === $contents || str_contains($contents, "\0")) {
                continue;
            }

            yield substr($file->getPathname(), \strlen($root) + 1) => $contents;
        }
    }
}
