<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Sylius\Activation;

use Koh\IndexNow\HostPolicy;
use Koh\IndexNow\Key;
use Sylius\Component\Core\Model\ChannelInterface;

/** Whether one host announces, for which channel and with which key — or why not. */
final readonly class Activation
{
    private function __construct(
        public bool $active,
        public string $reason,
        public ?Key $key,
        public ?ChannelInterface $channel,
        public ?HostPolicy $hostPolicy,
    ) {}

    public static function active(Key $key, ChannelInterface $channel, HostPolicy $hostPolicy): self
    {
        return new self(true, '', $key, $channel, $hostPolicy);
    }

    public static function inactive(string $reason, ?ChannelInterface $channel = null): self
    {
        return new self(false, $reason, null, $channel, null);
    }
}
